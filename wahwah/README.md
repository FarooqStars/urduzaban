# wahwah/ — «واہ واہ!» app on UrduZaban (Nizam FA-022, AI1-Claude1, 21 Nov 2026)

- index.html · privacy.html · terms.html — app information, privacy policy, terms (Urdu + English, site design).
- v1/index.json · v1/day/YYYY-MM-DD.json — the app's daily jokes + riddle (5 + 1 a day, nothing repeats within 30 days).
  Made in the wahwah repo by tools/make_fun_schedule.py (server/public/v1) and copied here.
  The app reads https://www.urduzaban.com/wahwah/v1 first; farooqmusic.com/wahwah/v1 is the backup.
- Owner's rule: the games are NOT played on the website; UrduZaban is only the app's information page and data home.
- Live data: api/wahwah.php (same admin key as lexicon.php) keeps the live pool + calendar outside public_html in uz-private/wahwah/; seeds = v1/pool.json + v1/calendar.json. Static v1/day files are the fallback.
- Control window: uz-control.html (report, daily content, jokes/riddles, app settings, website tools, record).
