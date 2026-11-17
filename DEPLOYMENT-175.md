# scheduled_send на хосте 175 — как это развёрнуто

Плагин «отправить позже» для Roundcube. Форк
[katsar0v/webmail-scheduled-send](https://github.com/katsar0v/webmail-scheduled-send)
с добавленной поддержкой SQLite.

## Что где лежит

| Что | Путь |
| --- | --- |
| Плагин | `/var/www/roundcubemail/plugins/scheduled_send/` |
| Настройки плагина | `/var/www/roundcubemail/plugins/scheduled_send/config.inc.php` (root:nginx 640) |
| Включение плагина | `/var/www/roundcubemail/config/config.inc.php`, строка `$config['plugins']` |
| Таблица очереди | `scheduled_send_queue` в `/var/lib/roundcube/sqlite.db` |
| Воркер | `/usr/bin/php /var/www/roundcubemail/plugins/scheduled_send/bin/worker.php` под юзером `nginx` |
| Таймер | `roundcube-scheduled-send.timer` + `.service` (раз в минуту) |
| Журнал воркера | `journalctl -u roundcube-scheduled-send.service` |
| Лог плагина | `/var/log/roundcubemail/` (канал `scheduled_send`) |
| Бэкапы | `config.inc.php.bak-20261117-101409`, `sqlite.db.bak-20261117-101409` |

Web-интерфейс: **http://127.0.0.1:8080** (порт 80 занят сервером Hermes с basic-auth).
Настройки → **Отложенные сообщения**; в окне «Написать» — кнопка **Send later**.

## Почему именно так

- **БД — SQLite.** PDO-драйвер SQLite собирает строковый литерал в одинарных кавычках,
  поэтому NUL-байт обрывает значение: 8-битное MIME-тело сохранялось пустым
  (256 байт → 0). Поэтому `headers`, `bcc`, `body` хранятся как base64 с префиксом `b64:`,
  а на чтении декодируются. MySQL/MariaDB пишут реальные blob'ы и байты не трогают —
  драйвер определяется через `rcube_db::$db_provider`, код общий.
- **Копия в Sent — через IMAP, не doveadm.** Воркер работает под `nginx`, а sudo ему
  недоступен. Здесь у Dovecot `passdb static` с `nopassword=yes`, поэтому подходит
  пароль-заглушка (`scheduled_send_imap_password`), и копия кладётся по IMAP на
  `127.0.0.1:143`.
- **Воркер под `nginx`, а не под `llm`/root.** Иначе SQLite WAL-файлы получили бы другого
  владельца и веб-интерфейс словил бы «database is locked».
- **В unit'е намеренно нет `ProtectSystem`/`ProtectHome`.** `/usr/sbin/sendmail` — это
  setgid-хелпер Postfix `postdrop`, ему нужно писать в `/var/spool/postfix/maildrop`.
  С read-only обзором ФС он падает с `mail_queue_enter: Permission denied`, и задание
  зависает в статусе `submitting`. Проверено: именно это и произошло при первой попытке.
- **`OnUnitInactiveSec`, а не `OnUnitActiveSec`.** Сервис — короткоживущий oneshot;
  при `OnUnitActiveSec` systemd уводил `next_elapse` в `infinity` и таймер замирал.

## Эксплуатация

```sh
# состояние очереди
sudo sqlite3 /var/lib/roundcube/sqlite.db \
  "select id,status,send_at,substr(subject,1,40),attempts from scheduled_send_queue;"

# прогнать воркер руками
sudo systemctl start roundcube-scheduled-send.service

# что происходит
sudo journalctl -u roundcube-scheduled-send.service -n 50 --no-pager
```

Статусы: `pending` → `submitting` → `accepted` → `done`. `uncertain` — воркер не смог
однозначно понять, принял ли sendmail письмо; такое задание **не переотправляется
автоматически** (защита от дублей) и требует ручного разбора.

## Проверка, которую я прогнал

1. `tests/queue_sqlite_check.php` — 15 проверок очереди на настоящем SQLite, включая
   побайтовое сохранение тела с NUL и 8-битным содержимым. `ALL CHECKS PASSED`.
2. Сквозная доставка: задание №2, письмо ушло (Postfix `pickup` uid=969 → relay
   `mail.te.oaotransenergo.ru` → `status=sent`), задание стало `done`, копия легла в
   `Sent` с флагом `\Seen`, кириллица в теле цела.
3. Round-trip: письмо, отправленное тем же путём (`sandbox-test`), вернулось в INBOX.

## Откат

```sh
sudo systemctl disable --now roundcube-scheduled-send.timer
sudo sed -i "s/, 'scheduled_send'//" /var/www/roundcubemail/config/config.inc.php
sudo rm -rf /var/www/roundcubemail/plugins/scheduled_send
sudo sqlite3 /var/lib/roundcube/sqlite.db "drop table if exists scheduled_send_queue;"
sudo systemctl restart php-fpm
```

Плюс при необходимости вернуть `config.inc.php.bak-20261117-101409` и
`sqlite.db.bak-20261117-101409`.
