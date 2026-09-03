# SERVER_CLEANUP_REPORT

Дата: 2026-09-03  
Хост: ubuntu-4gb-nbg1-1  
Пользователь: deploy  
Цель: удалить старый OpenClaw/Jarvis и оставить чистое окружение в `/var/www/jarvis`. Другие проекты не затрагивать.

---

## 1. Что было найдено (до очистки)

### User systemd service

- Unit: `openclaw-gateway.service` (user-level, пользователь `deploy`)
- Файл: `/home/deploy/.config/systemd/user/openclaw-gateway.service`
- Enable-symlink: `/home/deploy/.config/systemd/user/default.target.wants/openclaw-gateway.service`
- Состояние: `enabled`, `active (running)` с 2026-03-22
- PID: `193401`, процесс `openclaw-gateway`
- Описание: `OpenClaw Gateway (v2026.3.13)`
- ExecStart: `/usr/bin/node /usr/lib/node_modules/openclaw/dist/index.js gateway --port 18789`
- HOME: `/home/deploy`
- Маркеры: `OPENCLAW_SERVICE_MARKER=openclaw`, `OPENCLAW_SERVICE_KIND=gateway`, `OPENCLAW_GATEWAY_PORT=18789`

**Привязка подтверждена.** Сервис однозначно относится к OpenClaw / `/home/deploy/.openclaw` и не используется другими проектами. Другой user-unit в том же каталоге — `php85-shift-happens.service` (проект Shift Happens) — не трогался.

### Порты процесса OpenClaw (до остановки)

- `127.0.0.1:18789` и `[::1]:18789` — gateway
- `127.0.0.1:18791`, `127.0.0.1:18792` — дополнительные сокеты того же PID 193401

### Каталог данных

`/home/deploy/.openclaw` — полный state OpenClaw:

- `openclaw.json`, `openclaw.json.bak`
- `agents/`, `workspace/`, `credentials/`, `logs/`, `memory/`, `media/`
- `telegram/`, `devices/`, `identity/`, `canvas/`, `cron/`, `delivery-queue/`
- `completions/openclaw.{bash,zsh,fish,ps1}`
- `update-check.json`, `exec-approvals.json`

### Остатки в home `deploy`

| Объект | Вердикт |
|---|---|
| `/home/deploy/.config/systemd/user/openclaw-gateway.service` | OpenClaw — удалить |
| enable-symlink в `default.target.wants` | OpenClaw — удалить (снят `disable`) |
| `/home/deploy/.openclaw` | OpenClaw — удалить |
| Строки в `~/.bashrc` (`# OpenClaw Completion` + `source .../openclaw.bash`) | OpenClaw — удалить |
| `~/bin/php85` | НЕ OpenClaw (PHP 8.5 wrapper для Shift Happens) |
| `~/.config/systemd/user/php85-shift-happens.service` | НЕ OpenClaw |
| `~/.local/bin`, `~/.npm-global/bin` | отсутствуют |
| cron `deploy` | только `wow-cleaning` artisan schedule — не OpenClaw |
| системные unit-файлы `*openclaw*` / `*jarvis*` | не найдены |

### Глобальный npm-пакет (намеренно не тронут)

- `/usr/bin/openclaw` → `../lib/node_modules/openclaw/openclaw.mjs`
- `/usr/lib/node_modules/openclaw`

Принадлежит OpenClaw, но лежит в системном `/usr` (root). По правилу «не менять системные пакеты / глобальный Node.js/npm» не удалялся.

---

## 2. Что остановлено / отключено

Только user-level сервис OpenClaw:

```
systemctl --user stop openclaw-gateway.service
systemctl --user disable openclaw-gateway.service
```

Результат:

- сервис остановлен;
- enable-symlink удалён systemd;
- `systemctl --user daemon-reload` выполнен;
- `systemctl --user status openclaw-gateway.service` → `Unit ... could not be found`.

Другие systemd-сервисы (user и system) не останавливались и не отключались.

---

## 3. Какие файлы удалены

- `/home/deploy/.config/systemd/user/openclaw-gateway.service`
- `/home/deploy/.config/systemd/user/default.target.wants/openclaw-gateway.service` (снят `disable`; пустой каталог `default.target.wants` после этого отсутствует)
- весь каталог `/home/deploy/.openclaw`
- две строки OpenClaw completion из `/home/deploy/.bashrc`

---

## 4. Результаты финальных проверок

| Проверка | Результат |
|---|---|
| Процесс `openclaw-gateway` | не запущен (старый PID 193401 отсутствует) |
| Порт `18789` | свободен |
| Порты `18791`, `18792` | свободны |
| `/home/deploy/.openclaw` | отсутствует |
| Имена `*openclaw*` в `/home/deploy` | не найдены |
| Ссылки на openclaw/jarvis в `~/.bashrc` / `~/.profile` | нет |
| User unit OpenClaw | отсутствует |
| `php85-shift-happens.service` | файл на месте, не изменялся |
| `~/bin/php85` | на месте |
| `/var/www/jarvis` | существовал и был пуст до этого отчёта |
| `/var/www/{html,ofs,shift-happens,trueman,vpn,wow-cleaning}` | на месте, не изменялись |
| nginx | `active` |
| php8.3-fpm | `active` |
| php8.5-fpm | `active` |
| mysql | `active` |
| nginx sites-enabled | без изменений: `app.truemenflooring.ca`, `ofs`, `shifthappens.com.ua`, `sh.owlsolutions.net`, `vpn`, `wow-cleaning` |
| cron deploy | без изменений (`wow-cleaning` schedule) |
| User services после очистки | `dbus` running; OpenClaw больше не в списке |

Слушающие порты после очистки (без 18789/18791/18792): `80`, `443`, `22`, `3306`, `33060`, DNS stub, локальные node-порты Cursor (`42143`, `42953`).

---

## 5. Что специально НЕ изменялось

Ради безопасности остальных проектов не трогались:

- `/var/www/*` кроме `/var/www/jarvis`
- nginx (конфиги, sites, reload)
- PHP / PHP-FPM (8.3 и 8.5)
- MySQL/MariaDB, базы данных
- Redis
- Supervisor
- SSL / Certbot
- firewall
- другие systemd services (system и user), включая `php85-shift-happens.service`
- другие пользователи
- глобальный Node.js / npm (`/usr/bin/node`, `/usr/lib/node_modules/npm`, `/usr/lib/node_modules/corepack`)
- системный пакет `/usr/lib/node_modules/openclaw` и symlink `/usr/bin/openclaw`
- cron, SSH, `.ssh`, архивы в home (`moving-academy-*`, `landing-for-wow-cleaning.tar.gz`)
- Cursor / `.cursor` / `.cursor-server`

Не устанавливался Laravel.  
Не создавалась БД.  
Не настраивался nginx.  
Разработка нового Jarvis не начиналась.
