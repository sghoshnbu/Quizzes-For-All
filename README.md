# Quizzes-For-All
WordPress Quiz Maker Plugin 
=== Quizzis For All ===
Contributors: sghoshnbu
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete quiz and examination suite: eleven question types, manual grading, certificates, exam integrity, printable papers, Moodle interchange.

== Renamed in 1.6.0 ==

The plugin is now **Quizzis For All** (it was Quiz Master Core, a name that stopped fitting once it grew well past a "core"). The rename covers the plugin name, text domain, class names and file names.

Everything the plugin *stores* deliberately keeps its original naming so no existing site loses data: the results table, the quiz and question post types, the question-category taxonomy, and all settings. **Your existing quizzes, questions, categories and results all carry over untouched.**

Old `[qmc_*]` shortcodes stay registered alongside the new `[qfa_*]` ones, and the old Gutenberg block name stays registered too — so pages you have already published keep working with no edits.

== What's new in Phase 6 (1.5.0) ==

* **Student attempt review** — students can now open any past attempt from their dashboard and see each question, the answer they gave, the correct answer, instructor feedback and explanations. Access is strict: only the logged-in owner of an attempt can view it. A new per-quiz **Student Review** policy controls what is released — full review, full review only once graded, their own answers without the answer key, or no review at all — so exam papers don't leak answer keys between cohorts. Also available standalone via `[qmc_attempt_review]`.
* **Exam integrity pack** (per quiz, all opt-in): copy/right-click protection on question text (answer fields stay usable), tab-switch warnings with an optional auto-submit threshold, a server-side honeypot, a minimum-duration check, one-attempt-in-flight enforcement across tabs and devices, and an event log surfaced as an advisory flag on the grading screen. These raise the cost of casual cheating and record signals for review — they are not proctoring, and the plugin says so plainly in the admin UI.
* **Printable paper mode** — render any quiz as a print-ready question paper (masthead, time/marks line, candidate name-roll-date fields, instructions box, per-question mark allocations, OMR-style option boxes, ruled answer space for essays, shuffled matching columns) and, separately, a marked **answer key** with correct answers and explanations. Both link from the quiz editor's publish box and print cleanly to A4 or PDF.

== What's new in Phase 5 (1.4.0) ==

* **Redesigned admin dashboard** — gradient hero, live stat tiles (quizzes, bank size, attempts, grading queue, average score & pass rate), recent-attempts and quiz-performance panels, and a quick-links row. Styled admin CSS now applies across every Quiz Master screen.
* **Manual grading UI** — the missing half of essay and file-upload questions. A dedicated **Grading** queue (with a pending count badge in the menu) lists every attempt awaiting 
