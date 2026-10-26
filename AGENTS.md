# Project-local Git policy

- Project: AI Article Assistant / AI記事作成サポート only.
- Repository: https://github.com/cni-works/ai-article-assistant.git (exact match).
- Branch: main only. No force push or unrelated project files.
- This exported folder is the public repository root. Never add private development fixtures, evidence, credentials or client data.
- GitHub writes require an explicit subsequent user instruction to back up or release, plus prior review of public files and licenses. Preparation alone is not permission to push or publish.
- Before GitHub writes verify remote URL, main, working changes, then fetch. Stop on divergence/non-fast-forward.
- Do not bump versions, create/push tags, create releases or modify release assets without explicit user authorization.
- A failed build or ZIP verification blocks commit, push, tags and release publication.
- Use OS credential storage. Never store credentials in source, scripts or this file.
- Use WordPress APIs, input validation, output escaping, permission and nonce checks. Preserve existing article/settings/media compatibility.
- Run tools/build-release.ps1 after changes and the private full regression suite when available. Report real WordPress/ChatGPT operations as unverified unless actually tested.
- Never deploy directly to a live WordPress site.
