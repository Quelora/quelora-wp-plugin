# quelora-wp-plugin

**WordPress plugin for the [Quelora](https://github.com/Quelora) platform.**

[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue.svg)](./LICENSE)

Integrates Quelora — comments, engagement and community features — into any
WordPress site, without writing code.

## Features

- Embeds the Quelora widget into posts and pages
- Setup wizard that configures the connection to your Quelora backend
- Syncs WordPress posts and users to Quelora (batch upsert)
- Per-site configuration of the integration

## Installation

1. Copy the plugin into `wp-content/plugins/quelora/`
2. Activate it from the WordPress admin → Plugins
3. Run the setup wizard and enter your Quelora API URL and Client ID (`cid`)

## Architecture

Talks to [`quelora-dashboard-api`](https://github.com/Quelora/quelora-dashboard-api)
for sync and integration config, and embeds
[`quelora-widget-community`](https://github.com/Quelora/quelora-widget-community)
on the front end.

## License

[AGPL-3.0-only](./LICENSE) — Copyright (C) 2026 Germán Zelaya.

Part of the **[Quelora](https://github.com/Quelora)** project.
