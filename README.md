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
- Schedule: 投稿予定キューの管理
- Posted: 投稿済みのアーカイブ

CSRF対策付き。ステージ間の移動・削除をワンクリックで操作可能。

## License

MIT
