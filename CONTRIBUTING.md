# 開発に参加するには

環境の作り方は [README](README.md) を参照してください。

## 受け付ける Pull Request

- **先に issue で方針を合意してください。** 直したい点や足したい機能は、まず issue に書いてください。既存の issue に取り組むときも、着手する旨をコメントして方針をすり合わせてから Pull Request を出してください。合意の無い Pull Request は、内容にかかわらず閉じることがあります
- **Pull Request の本文には、対応する issue と、確かめた内容を書いてください。** テンプレートの欄が空のままのものは見ません
- **人が中身を確かめていない、自動で生成した Pull Request は受け付けません。** AI などの道具を使って書くこと自体は構いませんが、出す人が変更を読み、下の「Pull Request の前に流すもの」を手元で通し、説明に責任を持ってください
- 受け付けない Pull Request は、理由を書いて閉じます。同じ種類の Pull Request が続く場合は、以後のやり取りを制限することがあります

## ブランチとコミット

- `main` へ直接コミットせず、ブランチを切って Pull Request を出します
- ブランチ名は `feature/<issue の番号>-<簡潔な説明>` です
- コミットメッセージは `feat:`・`fix:`・`docs:`・`test:`・`chore:` などの接頭辞を付けて書きます

## Pull Request の前に流すもの

```bash
npm run lint:js
npm run test:js
npm run lint:php
npm run build
npm run test:php
npm run test:php:multisite
npm run i18n:check
npm run plugin-zip
```

CI は同じものに加えて、WordPress と PHP の最小の版でのテストと、Plugin Check を流します。

## 公式ディレクトリの表示（`.wordpress-org`）

`.wordpress-org` は公式ディレクトリの SVN の `assets` に配るファイルで、プラグインの zip には入りません。

- アイコン（`icon-128x128.png`・`icon-256x256.png`）とバナー（`banner-772x250.png`・`banner-1544x500.png`）
- スクリーンショット（`screenshot-<番号>.png`、日本語の画面は `screenshot-<番号>-ja.png`）。キャプションは `readme.txt` の Screenshots の節で、番号をそろえます
- Playground のプレビュー（`blueprints/blueprint.json`）。API キーを入れずに、見本のイベントと検索のページを作って開きます

blueprint は公式ディレクトリからプラグインを入れるので、公開前やリリースの前に手元で試すときは次で開きます。ディレクトリから入れる手順を、手元でビルドした zip に置き換えます。

```bash
npm run build
npm run plugin-zip
bash tests/playground.sh 9400        # http://127.0.0.1:9400/?pagename=upcoming-events
bash tests/playground.sh 9401 ja     # 日本語のサイトで開く（スクリーンショットを撮るとき）
```

## リリース

1. 本体のヘッダーの `Version`・`readme.txt` の `Stable tag`・`package.json` の `version` を同じ番号に上げ、`readme.txt` の Changelog を書いた PR を出してマージします（`tests/check-version.sh` が 3 か所のずれを止めます）
2. `main` で `v<版>`（例: `v1.2.0`）のタグを打って push します
3. Actions の Release が、タグのコミットが `main` に含まれ、版がタグとそろっていることを確かめ、配布用の zip の中身を SVN の `trunk` と `tags/<版>` に、`.wordpress-org` を `assets` に配ります

Release は環境 `wordpress-org` の Secrets `SVN_USERNAME`・`SVN_PASSWORD` を使います。パスワードは WordPress.org のアカウントの SVN 専用のものです。環境の配布先は `v*` のタグに限ってあります。Actions の手動の実行は、版の確かめと zip の組み立てまでで、環境にも SVN にも触れません。

Playground のプレビューのボタンは、blueprint を置くだけでは出ません。公開後に、プラグインの管理の画面（Advanced）でプレビューを public にします。

## 書くときの決まり

- PHP の名前空間は `EventWatch\`、グローバルに出す関数・option・transient の接頭辞は `eventwatch_` です
- 実行するファイルの先頭で `ABSPATH` を確かめます
- 画面の文言は英語を原文にして、テキストドメイン `eventwatch` で翻訳できる形にします
- このリポジトリは公開されています。API キーやパスワードなどの秘密情報と、非公開のリポジトリのパス・基盤の設定を、コード・issue・Pull Request に書かないでください
