# Release Notes for Interactive AI Assistant

## Unreleased

- The text color of each chat bubble can now be set, next to its background
  under **Settings → General → Bubble Colors**: one for the visitor's messages,
  one for the assistant's and one for replies from a live agent. Links in the
  bubble follow it. Left empty, the text color is still picked automatically to
  stay readable on the bubble, so nothing changes until you set one.
- Link preview cards now show an image for pages whose SEO field has none. The
  card falls back to the `og:image` the page itself declares — usually a default
  your templates set, or one derived from the hero image — which is the same
  image the page shows when shared on social networks. Only the page's `<head>`
  is downloaded to find it, and the result is cached for an hour like the rest of
  the card.
- Pages the assistant links to now get a preview card consistently. A card used
  to appear only when the model happened to put the link on a line of its own;
  a link worked into a sentence — "you can see his profile here" — stayed a
  plain link, and some answers about a page left the link out entirely. A link
  inside a sentence now keeps working as a link and also gets its card underneath
  the paragraph, and the assistant is told to link the page whenever its answer
  is about something that has one.
- Answers arrive in fewer round trips to OpenAI. A turn used to take up to five
  calls in a row, each waiting on the one before it, and three of them were
  spent on preparation rather than on the answer. Two are now gone. The first
  message of a conversation no longer pays for a rewrite step whose whole job is
  resolving references to earlier messages that do not exist yet. And when the
  assistant has already answered and then shows a form for the visitor to fill
  in, it no longer makes a second full call to the model just to add a line
  above it — the widget was already rendering the form regardless. If it has not
  answered yet, that call still happens: the answer comes first, always.
- The assistant no longer asks whether it should open a form and then opens it
  in the same breath. Displaying a form is instant, so it answers the visitor's
  question in full, opens the form, and adds one line pointing at it — instead
  of offering and waiting for a "yes" that arrives after the form is already on
  screen. A form is never offered in place of an answer.
- Fixed the opening of a reply disappearing when the assistant used a skill or a
  form mid-answer. What it had written before reaching for the tool was shown as
  it arrived and then dropped when the finished reply replaced it.
- Slow answers can now be explained rather than guessed at. Every turn writes one
  line to the Craft log breaking its seconds down by stage — rewriting the
  question, embedding it, searching the index, reranking, and the model itself,
  with the number of model round trips it took. A turn where the assistant calls
  a tool (offering a form, running a skill) costs two full model calls over the
  same long prompt, which is the usual reason a reply that reads as simple took
  twice as long as one that doesn't. `rag/ask` prints the same breakdown, and
  `rag/retrieve` now separates the time spent embedding a query from the time
  spent searching, so the index's own cost is visible on its own.
- Searching the index no longer re-reads the whole trained corpus to answer one
  question. The word statistics the lexical half of search needs — how many
  chunks there are, how long they are, how many contain each word — describe what
  has been trained, not what was asked, so they are worked out once and reused
  until training changes them. Sites with a lot of trained content get the
  clearest benefit; nothing about the answers changes.
- Choice and checkbox fields in a conversational form can now take their options
  from the site instead of a typed list. Under Forms → *(a form)* → Fields, the
  Options column has a source picker: **Typed list** (as before), **Entries in a
  section** — the titles of that section's live entries — or **A field's options**
  — the choices of a Craft dropdown, radio, checkboxes or multi-select field. The
  list is read when the assistant offers the form, so publishing an entry or
  editing the field is enough; nothing has to be re-typed here. On a multi-site
  install a section's titles come from the site the visitor is on. Lists are
  capped at 200 choices and refresh within about five minutes.
- The chat window no longer drags the visitor back down while an answer is
  still being written. Scrolling up now detaches the view, so a long reply can
  be read from the top while the rest of it streams in; scrolling back to the
  bottom re-attaches it, and sending a message or reopening the widget always
  jumps to the newest message.
- The message box stays writable while the assistant is thinking. The Send
  button is still locked until the reply lands — one question at a time — but a
  visitor can type or paste the next one in the meantime, and pressing Enter too
  early leaves the draft in the box instead of discarding it.
- The chat window's size and its text size are now settings, under Settings →
  General: **Chat Window Width**, **Chat Window Height** and **Text Size**. Every
  text size inside the widget is a ratio of that one base size, so raising it
  scales bubbles, labels, buttons and timestamps together instead of leaving a
  mix behind; the panel still never grows past the viewport.
- Visitors can also enlarge the widget themselves. **Let Visitors Resize** (on by
  default) puts *Enlarge window* and *Text size* (Normal → Large → Largest) in the
  widget's ⋯ menu, and each visitor's choice is remembered on their own device
  and applied on top of the configured size — so someone who needs bigger text
  gets it without changing what everyone else sees. In Agent mode the docked
  panel widens with the page margin beside it, so the page never ends up
  underneath it.
- Conversational forms take two more field types. **Checkboxes** is a group the
  visitor ticks any number of — *Extra services: rubble removal, dismantling* —
  built from the same comma-separated options a Choice field uses; the answer is
  stored as a list, joined with commas for email and Contact Form delivery and
  sent as a JSON array to a webhook. **Consent checkbox** is the single GDPR or
  terms box, with its link written straight into the label as
  `I agree to the [privacy policy](/privacy-policy)` — only `http(s)://`,
  root-relative and `mailto:` targets become links, so a label still cannot
  inject markup. A required consent box blocks submission until it is ticked,
  and in a conversational form the model has to have been given an explicit yes.
- A trained index can now be moved between installs. **Training → Transfer**
  (and `rag/export` / `rag/import`) writes every trained source, its vectors and
  the documents behind them to one gzipped bundle, and reads one back — so a
  site can be trained on a local copy and go live already trained, instead of
  re-embedding everything on a site that is already serving visitors. Content is
  matched by element UID and site handle rather than by the ids it had where it
  was trained, and anything the target does not have is reported instead of
  being attached to the wrong page. Bundles from a different embedding model are
  refused unless imported with `--reembed`, which embeds the content on the
  target instead.
- Conversational form definitions travel in that bundle too, so a form built and
  tried out on a copy no longer has to be typed out again on the live site. A
  form the target already defines is kept as it is unless the import asks for it
  to be replaced — its delivery config is the live site's, and a definition from
  a copy would send real submissions somewhere else — and a form that is new to
  the target arrives at admins-only however it was set where it came from, so it
  can be tried against this site's own webhook or mailbox before visitors reach
  it. Submissions stay where they were made.
- Encrypted PDFs whose own permissions allow text extraction are now indexed,
  via `pdftotext` or `qpdf` when either is installed, instead of failing with
  "Secured pdf file are currently not supported". A PDF that needs a password to
  open still reports that it cannot be read.
- Running headers, footers and watermarks repeated on every page of a PDF are
  dropped before indexing, so they stop matching every query.
- A failed file upload no longer reloads the page out from under its own error
  message. Failures stay on screen until the next upload run, and a batch where
  some files worked says which ones did not.
- Upload errors say what actually went wrong: the file's real size against the
  real limit, PHP's own `upload_max_filesize`/`post_max_size` when either is
  stricter, a web server 413, an unwritable upload directory, or a response that
  was not JSON — which previously stalled the queue on "Uploading…" forever.

## 1.0.0

- Initial release.
