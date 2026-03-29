#!/usr/local/php/8.1/bin/php
<?php
/**
 * ig_scheduler batch publisher
 * Finds posts in schedule.json where scheduled_at has passed, publishes them to Instagram.
 * Usage: php publish_batch.php [--dry-run]
 */

date_default_timezone_set("Asia/Tokyo");
$dry_run = in_array("--dry-run", $argv ?? []);
$base_dir = __DIR__;
$data_dir = $base_dir . "/data";
$accounts = require $base_dir . "/config.php";
$now = new DateTimeImmutable();

function save_stage($path, $data) {
    usort($data["posts"], function($a, $b) {
        $sa = $a["scheduled_at"] ?? "";
        $sb = $b["scheduled_at"] ?? "";
        if ($sa === "" && $sb === "") return 0;
        if ($sa === "") return 1;
        if ($sb === "") return -1;
        return strcmp($sa, $sb);
    });
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function log_msg($msg) {
    $ts = date("Y-m-d H:i:s");
    echo "[{$ts}] {$msg}\n";
}

// Load schedule
$sched_path = "$data_dir/schedule.json";
$sched = json_decode(file_get_contents($sched_path), true) ?? ["posts" => []];
$posted_path = "$data_dir/posted.json";
$posted = json_decode(file_get_contents($posted_path), true) ?? ["posts" => []];

$due = [];
foreach ($sched["posts"] as $i => $post) {
    $sa = $post["scheduled_at"] ?? "";
    if ($sa === "") continue;
    $scheduled = new DateTimeImmutable($sa, new DateTimeZone("Asia/Tokyo"));
    if ($scheduled <= $now) {
        $due[] = $i;
    }
}

if (empty($due)) {
    log_msg("No posts due. Exiting.");
    exit(0);
}

log_msg(count($due) . " post(s) due for publishing.");

// Process in reverse order to keep indices valid during splice
foreach (array_reverse($due) as $idx) {
    $post = $sched["posts"][$idx];
    $id = $post["id"] ?? "unknown";
    $acct_name = $post["account_name"] ?? "";
    $caption = $post["caption"] ?? "";
    $image_urls = $post["image_urls"] ?? [];

    log_msg("Publishing: {$id} (@{$acct_name})");

    if (empty($image_urls)) {
        log_msg("  SKIP: no images");
        continue;
    }
    if (!$acct_name || !isset($accounts[$acct_name])) {
        log_msg("  SKIP: unknown account '{$acct_name}'");
        continue;
    }

    if ($dry_run) {
        log_msg("  DRY-RUN: would publish {$id}");
        continue;
    }

    $acct = $accounts[$acct_name];
    $ig_id = $acct["ig_account_id"];
    $tk = $acct["access_token"];
    $is_carousel = count($image_urls) > 1;

    try {
        if ($is_carousel) {
            // Carousel: create child containers
            $children = [];
            foreach ($image_urls as $img_url) {
                $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        "image_url"      => $img_url,
                        "is_carousel_item" => "true",
                        "access_token"   => $tk,
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                ]);
                $res = json_decode(curl_exec($ch), true);
                curl_close($ch);
                if (empty($res["id"])) {
                    throw new RuntimeException("child container failed: " . ($res["error"]["message"] ?? "unknown"));
                }
                $children[] = $res["id"];
            }

            // Wait for all children
            foreach ($children as $child_id) {
                for ($w = 0; $w < 10; $w++) {
                    sleep(2);
                    $ch = curl_init("https://graph.instagram.com/v22.0/{$child_id}?fields=status_code&access_token={$tk}");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $status = json_decode(curl_exec($ch), true);
                    curl_close($ch);
                    if (($status["status_code"] ?? "") === "FINISHED") break;
                    if (($status["status_code"] ?? "") === "ERROR") {
                        throw new RuntimeException("child container error: {$child_id}");
                    }
                }
            }

            // Create carousel container
            $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    "media_type"   => "CAROUSEL",
                    "children"     => implode(",", $children),
                    "caption"      => $caption,
                    "access_token" => $tk,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $creation_id = $res["id"] ?? null;
        } else {
            // Single image
            $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    "image_url"    => $image_urls[0],
                    "caption"      => $caption,
                    "access_token" => $tk,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            $res = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $creation_id = $res["id"] ?? null;
        }

        if (!$creation_id) {
            throw new RuntimeException("container failed: " . ($res["error"]["message"] ?? "unknown"));
        }

        // Wait for container
        for ($w = 0; $w < 10; $w++) {
            sleep(2);
            $ch = curl_init("https://graph.instagram.com/v22.0/{$creation_id}?fields=status_code&access_token={$tk}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $status = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (($status["status_code"] ?? "") === "FINISHED") break;
            if (($status["status_code"] ?? "") === "ERROR") {
                throw new RuntimeException("container error");
            }
        }

        // Publish
        $ch = curl_init("https://graph.instagram.com/v22.0/{$ig_id}/media_publish");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                "creation_id"  => $creation_id,
                "access_token" => $tk,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (empty($res["id"])) {
            throw new RuntimeException("publish failed: " . ($res["error"]["message"] ?? "unknown"));
        }

        // Get permalink
        $media_id = $res["id"];
        $ch = curl_init("https://graph.instagram.com/v22.0/{$media_id}?fields=permalink&access_token={$tk}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $pres = json_decode(curl_exec($ch), true);
        curl_close($ch);

        // Move to posted
        $post["posted_at"] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $post["ig_media_id"] = $media_id;
        $post["permalink"] = $pres["permalink"] ?? "";
        array_splice($sched["posts"], $idx, 1);
        array_unshift($posted["posts"], $post);

        log_msg("  OK: {$post["permalink"]}");

    } catch (RuntimeException $e) {
        log_msg("  ERROR: " . $e->getMessage());
        continue;
    }
}

// Save
save_stage($sched_path, $sched);
save_stage($posted_path, $posted);
log_msg("Done.");
