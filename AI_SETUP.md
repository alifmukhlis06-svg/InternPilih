# Gemini AI setup

InternPilih uses Gemini only when a Coordinator selects **Generate AI Summary** for a student already flagged by the fixed offer rules.

## Configure the API key

1. Copy `config/gemini.local.example.php` to `config/gemini.local.php`.
2. Replace `your-gemini-api-key` with a Google AI Studio API key.
3. Keep `config/gemini.local.php` private. It is excluded by `.gitignore`.

Alternatively, set `GEMINI_API_KEY` and optionally `GEMINI_MODEL` in the web server environment.

## Data sent to Gemini

Only these anonymous fields are sent:

- configured response-period days;
- pending and accepted offer counts;
- deterministic flag reasons;
- pending-offer ages in days; and
- overdue durations in days.

Names, matric IDs, programmes, company names, positions, and exact dates are not sent.

## Use

Open **Coordinator > Monitoring**, select a flagged student, and choose **Generate AI Summary**. Gemini provides a plain-language summary plus advisory urgency, contributing indicators, a recommended follow-up and timeframe, and a draft reminder. These additional outputs are separate advisory features; the fixed system rules remain authoritative.
