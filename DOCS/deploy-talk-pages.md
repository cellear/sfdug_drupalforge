# Talk companion pages: what changed, how to deploy it, what to do afterwards

Built locally 2026-11-09 for Luke's two BADCamp 2026 talks. Nothing here has
been committed, pushed or deployed. Commands below are written for pair
Networks and were **not run** there.

## What changed (content model)

Meeting (`session`) gained two fields. No new content type, no custom module.

| Field | Type | Purpose |
|---|---|---|
| `field_event` | list, required, default `sfdug` | `sfdug` SFDUG meeting · `badcamp` · `drupalcon` · `other`. Only `sfdug` appears in `/meetings`, `/upcoming`, the home hero/infobar blocks and the front-page accent colour. `/videos` shows every event. |
| `field_discussion` | comment (type `meeting_comment`, label "Discussion") | Threaded, 50 per page, form below the comments, preview off. New Meetings default to **open**; the backfill sets every existing Meeting to **hidden**. |

Around them:

- Core `comment` module enabled. Comment type `meeting_comment` with
  `comment_body` only (subject hidden on the form; the auto-subject is hidden
  in CSS).
- Anonymous and authenticated: *access comments*, *post comments*. No role has
  *skip comment approval*, so every comment waits at
  `/admin/content/comment/approval`.
- Anonymous commenters may leave name / email / homepage, all optional. Email
  is never displayed. Core stopped logging commenter IPs by default (D10.1+),
  so `hostname` stays empty.
- Honeypot protects `comment_meeting_comment_form` (5-second time limit).
  Friendly Captcha is **not** on the form: its site key and secret in
  config/sync are unfilled placeholders.
- ECA model `comment_moderation_notice`: on insert of a `meeting_comment`
  with status 0, emails `luke@kiza.com` (page title, URL, author, body, link
  to the approval queue). Verified in Mailpit locally. Live mail delivery is
  unverified; see step 8.
- `views.view.sfdug_meetings`: filter `field_event = sfdug` on all nine
  displays. `sfdug.theme` `sfdug_next_session()` has the same condition.
- Theme: `node--session--full.html.twig` renders a "Discussion" section after
  Slides; `css/sections.css` gained a `.session-full__discussion` block.
- Pathauto's existing pattern already aliases new Meetings from the title
  (verified: the local test node got `/test-badcamp-content-travels`).

Config files added (17) and changed (13) are listed in
`HANDOFF/handoff-2026-11-09-talk-pages-claude.md`. Scripts:
`scripts/build_talk_pages.php` (how the config was made; do **not** run on
live) and `scripts/backfill_talk_pages.php` (run on live, step 6).

## Deploy to pair

Facts from the 2026-09-08 handoff: project root `~/public_html/sfdug.org/df`,
docroot `df/web`, database `cellear_dpsfdug`, PHP must be
`/usr/local/bin/php83` (plain `php` is 8.2 and fails). No Composer step is
needed: `comment` is core and every other module is already installed, so
`web/.htaccess` is not touched and `pair-post-deploy.sh` is not needed.

```sh
ssh <pair>
cd ~/public_html/sfdug.org/df
D="/usr/local/bin/php83 vendor/bin/drush"
```

1. **Backup first.**
   ```sh
   mkdir -p ~/backups
   $D sql:dump --gzip --result-file=$HOME/backups/sfdug-pre-talk-pages-$(date +%F).sql.gz
   ls -la ~/backups
   ```
2. **Look at live drift before anything else.** This is live's first config
   import; the site was deployed by database import.
   ```sh
   $D config:status
   ```
   "No differences" is the hope. Anything listed means someone changed config
   through the live UI since September; `cim` would revert it. Show Luke the
   list and decide per item before continuing (export it from live, or accept
   losing it).
3. **Get the code.** Luke commits and pushes locally; then:
   ```sh
   git status            # expect: web/.htaccess modified (pair's PHP handler), nothing else
   git pull --ff-only
   ```
4. **Preview, then import.**
   ```sh
   $D config:import --preview=list     # expect: Install comment; create 17; update 13
   $D config:import -y
   $D updatedb -y                      # expect nothing to do
   ```
5. **Check the import landed.**
   ```sh
   $D config:status                    # expect: No differences
   $D pm:list --status=enabled --filter=comment
   ```
6. **Backfill existing Meetings** (field_event = sfdug, comments hidden).
   Run before creating the BADCamp nodes.
   ```sh
   $D php:script scripts/backfill_talk_pages.php
   # expect: Meetings: 79 total. field_event set on 79, comments hidden on 79, untouched 0.
   $D cache:rebuild
   ```
7. **Smoke test.** Home, `/meetings`, `/upcoming`, `/videos` and one older
   meeting page should look exactly as before. No "Discussion" heading on any
   existing meeting.
8. **Mail test.** The moderation notice depends on pair delivering mail from
   the site. Post a test comment on the first BADCamp node (step 9) and check
   `luke@kiza.com`. If nothing arrives, the approval queue at
   `/admin/content/comment/approval` still works; mail is a convenience.

### Rollback

```sh
cd ~/public_html/sfdug.org/df
git log --oneline -3                      # note the commit before the feature
git checkout <previous-commit> -- .       # or: git reset --hard <previous-commit>
gunzip -c ~/backups/sfdug-pre-talk-pages-<date>.sql.gz | $D sql:cli
$D cache:rebuild
```

The previous site is still at `~/public_html/sfdug.org/df_old` on database
`cellear_sfdug` as a last resort (docroot switch in pair's panel).

## After deploy: content, by hand

1. **Create the two Meetings** at `/node/add/session`:
   - Title: "Wagtail and Drupal: What Each System Can Learn from the Other".
     Date Thu 12 Nov 2026, 4:00 PM. Event: **BADCamp**. Attendance mode: in
     person. Location: the BADCamp room. Body: abstract, then a line such as
     "Questions in advance? Leave them below. Every comment is read before it
     appears." Slides: upload the file, or put the deck's URL in the body.
     Discussion: **Open** (the default).
   - Same for "Content That Travels: Comparing Migration Formats for Drupal",
     Fri 13 Nov 2026, 2:25 PM.
   - Neither will show in `/meetings`, `/upcoming` or the home hero. Both
     show in `/videos` once a recording is attached.
2. **QR redirects** at `/admin/config/search/redirect/add`: `/travels` and
   `/wagtail` to the two node aliases (pathauto makes them from the title;
   check them on the node page).
3. **During and after the talks:** approve comments at
   `/admin/content/comment/approval`. Add the YouTube recording to
   `field_session_video` when it is up.

Last updated: 2026-11-09 by claude
