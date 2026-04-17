# Pointercrate-PHP

Pointercrate-inspired demonlist built with PHP + MySQL.

## Features

- Main List / Extended List / Legacy List.
- Level detail page with scoring, records, and position history.
- Record submission to player account.
- Admin panel for level management, rank changes, and user roles.
- Discord webhook notifications.

## Requirements

- PHP >= 8.1
- MySQL or MariaDB

## Update database

When updating, remember to change the value `'updated' => 0` to update the database.

## Quick Setup

1. Upload the project to your hosting path, for example `public_html/demonlist`.
2. Create a MySQL database and user in your hosting panel.
3. Import schema.sql into that database
4. Edit `config.php` with your values:

- `name`: website name
- `tagline`: short site description
- `base_url`: app base path (example `/demonlist`)
- `public_url`: absolute public URL for embeds/meta
  - example: `https://your-gdps.com/demonlist`
- `timezone`: app timezone
- `debug`: debug mode

### Discord

- `webhook_url`: event notifications (submit/review/level updates)
- `server_widget_url`: full widget URL
  - example: `https://discord.com/widget?id=...&theme=dark`
- `server_id`: used if `server_widget_url` is empty
- `server_theme`: `dark` or `light`
