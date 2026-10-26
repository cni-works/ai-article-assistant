# リリース手順

対象は `https://github.com/cni-works/ai-article-assistant.git`、ブランチは `main` に固定します。別のリポジトリ・ブランチ・WordPressサイトへ公開しないでください。

## 初回登録

公開対象を確認してから、用意した公開用ソースフォルダーをGit管理します。この手順書自体はPush・タグ作成・Release公開を実行しません。

```powershell
git init -b main
git remote add origin https://github.com/cni-works/ai-article-assistant.git
git remote -v
git fetch origin
git branch --show-current
git status --short --untracked-files=all
```

remote URLとmainが完全一致することを確認してください。既存コミットが取得された場合は、その履歴を確認・統合してから進めます。分岐・非fast-forwardを強制Pushで解消しないでください。初回監査時点では空の公開リポジトリでしたが、登録時に再確認が必要です。

公開する差分・除外・ライセンスを確認して承認された後、対象ファイルだけをadd・commitしてmainへ通常Pushします。GitHub認証はOSのCredential Storeなどを使い、トークンをソース・remote URL・コマンド本文へ保存しません。

リポジトリ直下にプラグインのメインPHP・includes・assets・prompts・vendorを置く構成です。利用者にはGitHubの自動生成ソースZIPを配布しません。

## 検査と配布ZIP

PHP 8.0以上、PowerShell 7、Node.jsを使います。公開用ソースでは次を実行します。

```powershell
pwsh ./tools/build-release.ps1 -ExpectedVersion 0.1.0
```

公開用ビルドはPHP・JavaScript構文、同梱PUCを使う更新テスト、ライブラリの固定ハッシュ、バージョン整合性とZIP全内容を照合します。公開用テストには実サイト由来のCSV・実データを含めていません。開発環境で行う記事・画像・設定の全回帰テストは別途実施してください。公開用の小さな更新テストを全機能テストと扱わないでください。

出力は `dist/ai-article-assistant.zip` とSHA256。ルートは必ず次の形です。

```text
ai-article-assistant/
  ai-article-assistant.php
  includes/
  assets/
  prompts/
  vendor/plugin-update-checker/
  readme.txt
  README.md
  CHANGELOG.md
  LICENSE
  THIRD-PARTY-NOTICES.md
  docs/RELEASING.md
```

tests・tools・.git・秘密情報・検証記録はインストールZIPへ入れません。ファイル許可一覧 `release-files.json` と第三者ファイルの `tools/puc-5.7-manifest.json` は管理者が変更内容を確認して更新します。ビルドエラーを無視して出荷しません。

## 0.1.0の初回Release

1. バージョンを確認：メインPHPのVersion、AAA_VERSION、readme.txtのStable tag、配布情報が0.1.0で一致していること。
2. 自動検査・検証サイトでの動作確認・公開範囲の確認を完了する。
3. 承認後、mainの対象コミットに注釈付きタグ `v0.1.0` を作成し、通常Pushする。既存タグを上書きしない。
4. GitHubでタグを指定した**下書きRelease**を作成する。以下のリリースノートと検証済み `ai-article-assistant.zip`、SHA256を添付する。
5. 添付ファイルのダウンロード・ハッシュ・ルート構造を再確認する。公開承認後に正式版として公開する。

下書きは通知対象になりません。準備ができる前に正式Releaseを公開しないでください。添付のない正式Releaseを先に公開すると、更新機構は安全側に更新なしと判定します。

初回ノートの例：

```text
AI記事作成サポート v0.1.0

ChatGPTとの手動相談、記事CSV v2と画像・PDFの取り込み、Gutenberg下書き、
サイト・記事の種類・共通品質設定、管理者UIを含む初回版です。
GitHub正式Releaseの指定ZIPに限定した更新機構を同梱しています。
WordPress 6.3以上／PHP 8.0以上。APIキーは不要です。

既存のアップデーター未搭載版からは、このZIPによる手動置換更新が必要です。
同じ0.1.0同士では更新通知は出ません。
検査結果・実機確認範囲・残る制限と、実際のZIPのSHA256を記載してください。
```

## 正式版・テスト版と0.1.1

- 正式版は `vX.Y.Z` とし、タグ・メインPHP・定数・Stable tag・ZIPが一致した版だけを公開する。
- `main`へのPushやタグのみでは本プラグインの更新対象にならない。
- プレリリース、下書き、`v0.1.1-rc.1` 等は一般サイトへ配信しない。テスト目的の0.1.1正式Releaseは作らない。
- 実際の修正を0.1.1として公開する場合は、承認後に各バージョン表記とCHANGELOGを一緒に更新し、ビルド・全回帰・検証サイトの更新実証を行う。
- この初回準備では0.1.1へ書き換えず、Gitタグも作らない。更新ライブラリのテストはメモリー内の0.1.1メタデータを使い、公開はしない。

### 公開前のWordPress実証

1. 検証サイトにアップデーター付き0.1.0を手動導入。DB、uploads、現在のZIPをバックアップし、サイト情報・種類・カテゴリー・制作中の記事ID・メディアIDを控える。
2. 更新確認操作とWP-Cronを試し、0.1.0正式版しかない場合は新規通知が出ないことを確認する。プラグインの詳細で更新履歴を確認する。
3. 0.1.1を公開せずに更新UIとファイル置換を試す場合は、**隔離した検証サイトのみ**で、一時的なテスト用MUプラグインによりこのリポジトリのRelease・contentsへのHTTP応答をローカルの0.1.1テストデータへ置き換える。ダウンロードURLは本番と同じ形式を使い、そのURLに対する `upgrader_pre_download` だけをローカルの検証済み0.1.1 ZIPへ差し替える。全Version表記を揃えたコピーで行い、テストデータ・フック・認証情報を製品へ同梱しない。
4. WordPress標準の更新操作で置換し、プラグインのパス・有効状態、管理者UI、設定、基本→詳細CSV、記事CSV v2、画像・PDF・ダミー、Gutenbergの編集・再保存を確認する。
5. 通信失敗・ZIP未取得・更新不可のファイル権限のケースは、それぞれ隔離環境で確認し、WordPressのエラーと復旧手順を記録する。復旧はWordPress／サーバーの方式に依存し、本プラグインが完全復旧を保証するものではない。
6. テスト用フックを削除して検証サイトを元に戻す。この方法では実GitHub配信経路のエンドツーエンド確認は完了しない。実際に必要な次の正式版ができた段階で、本物のGitHub経由更新を確認する。

一般サイトの更新元をテスト配信へ切り替えないでください。検証前に「WordPress更新成功」「実DB保持確認済み」と報告しないでください。
