# bonsoleil-tools

bon-soleil.com で運用するInstagram関連ツール群。

## 構成

```
tools/
├── ig_hosting/       # IG投稿用画像のホスティング＆ビューア
│   ├── index.php     # グリッド表示＋ライトボックスビューア
│   └── *.png|*.jpg   # 投稿画像ファイル
├── ig_scheduler/     # IG投稿スケジューラ
│   ├── index.php     # Draft → Schedule → Posted の3ステージ管理UI
│   ├── config.php    # 認証設定（gitignore対象）
│   └── data/         # 各ステージのJSONデータ
│       ├── draft.json
│       ├── schedule.json
│       └── posted.json
├── index.html        # ツールトップページ
├── LICENSE           # MIT License
└── README.md
```

## ig_hosting

Instagram投稿用の画像をホストするPHPアプリ。
ディレクトリ内の画像ファイルを更新日時降順でグリッド表示し、クリックでライトボックス拡大表示できる。

## ig_scheduler

Instagram投稿のワークフロー管理ツール。
投稿を **Draft → Schedule → Posted** の3ステージで管理するPHP製Web UI。

- Draft: 下書き投稿の一覧・編集
- Schedule: 投稿予定キューの管理・即時投稿
- Posted: 投稿済みのアーカイブ

CSRF対策付き。ステージ間の移動・削除をワンクリックで操作可能。
ScheduleステージからMeta Graph APIを使ったInstagram即時投稿に対応。

### セットアップ

```bash
cd ig_scheduler
cp config.php.sample config.php
```

`config.php` を編集し、Meta Graph APIの認証情報を設定する。

```php
<?php
return [
    'account_name'  => 'your_account_name',
    'ig_account_id' => 'YOUR_IG_BUSINESS_ACCOUNT_ID',
    'access_token'  => 'YOUR_ACCESS_TOKEN',
];
```

- **account_name**: Instagramアカウント名
- **ig_account_id**: Instagram Business Account ID（Meta Business Suiteで確認）
- **access_token**: Meta Graph APIの長期アクセストークン

`config.php` は `.gitignore` に含まれており、リポジトリにはコミットされない。

## License

MIT
