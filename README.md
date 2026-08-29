# Familypedia

- Contributors: akirk
- Tags: family, wiki, genealogy, gedcom
- Tested up to: 7.1
- Requires PHP: 7.4
- License: [GPLv2 or later](http://www.gnu.org/licenses/gpl-2.0.html)
- Stable tag: 1.0.0

Like Wikipedia, but private and just for your family.

[Try Familypedia in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/familypedia/main/blueprint.json) · [Try it with demo data](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/familypedia/main/demo.json)

[Try it in OpenStation](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/familypedia/main/blueprint-openstation.json) — the same app opened in desktop mode with the [OpenStation](https://github.com/WordPress/openstation) plugin.

## Description

Familypedia turns your WordPress site into a private, Wikipedia-style
encyclopedia about your family. Each relative gets their own page for their
story and photos, and Familypedia builds the family calendar and birthday
reminders automatically from the dates you enter.

It's also compatible with the family tree apps you may already use:
Familypedia reads and writes GEDCOM files, the format used by Ancestry,
MyHeritage, Gramps, and most other genealogy software. Import what you have
and the tree becomes a starting point, not the project — so you can spend
your time on the stories and photos a tree alone can't hold.

Under the hood, Familypedia is a [WpApp](https://github.com/akirk/wp-app)
version of [Family Wiki](https://github.com/akirk/family-wiki), with two
deliberate differences:

- **People are a custom post type**, not WordPress pages, so the family wiki
  does not take over the site it lives on.
- **No Advanced Custom Fields.** Facts are plain post meta, and they are edited
  by a form inside the app.

The whole wiki lives under one path, `/familypedia/`, rendered by the app's own
templates rather than by the site's theme.

## How editing works

Two halves, each where it belongs:

- **The text about a person** is written in the block editor in wp-admin, under
  *Familypedia → People*.
- **The facts about a person** — dates, places, parents, children, marriages —
  are edited in the app itself, at `/familypedia/<name>/edit`.

Relatives are entered by name. A name that matches somebody on the wiki becomes
a link to them; one that does not is kept as plain text, so a grandmother can be
recorded before anyone writes her page. Where two people share a name the picker
offers each of them with their birth year.

## Pages

| Path | What it is |
| --- | --- |
| `/familypedia/` | The home page, laid out in the block editor |
| `/familypedia/all` | Everyone and every page, grouped by initial, with a search |
| `/familypedia/<name>` | A person, their infobox and their text |
| `/familypedia/<name>/edit` | The form for that person's facts |
| `/familypedia/<name>/<page>` | A page hung beneath a person |
| `/familypedia/calendar` | The family calendar, month by month |
| `/familypedia/calendar/<month>` | One month on its own |
| `/familypedia/birthdays` | Birthdays of the living |
| `/familypedia/tree` and `/familypedia/tree/<name>` | Descendant outlines |
| `/familypedia/new` | Add a person |
| `/familypedia/new-page` | Add a standalone page, with no person of its own |

The calendar and birthday pages can be switched off in *Settings → Familypedia*.

## The front page

The home page is a post, edited in the block editor under *Familypedia → Front
Page* or from the *Edit this page* link at the foot of the page itself. It starts
out holding the two blocks the page used to be built from — the highlights box
and the recently updated list — so a family tree on the home page is a matter of
adding the Family Tree block and choosing where the branch starts. A GEDCOM
import offers to do that for you.

Emptying the post puts those two blocks back: the home page is never blank.

## Roles and privacy

People use WordPress's page capabilities, so *Editor* and *Administrator* can
edit them straight away. The plugin also adds *Wiki User* (can edit) and
*Wiki Editor* (can also delete).

Everything under `/familypedia/` requires a login; the rest of the site is
left as it is.

## Wiki links

Links in a person's text are resolved as the page is rendered: a link to
somebody who exists points at their page in the app, one to somebody who does
not is marked red and offers to create them, and a link that leaves the site is
marked green.

## GEDCOM import and export

Administrators import and export GEDCOM files on the app's own
*Import / Export* page. An upload opens a review step, which leads with a button
that takes the whole file — the usual answer, and one click above the table
rather than a scroll through it. Below that you can pick individual entries or
whole descendant subtrees instead.

The review step also offers to put the biggest branch on the front page as a
family tree. It is ticked to begin with on a wiki that has nobody on it yet,
where the import is the whole family, and left alone once the front page already
draws a tree.

Importing runs a batch of the file per request and says how far it has got —
people first, then the family records that link them — because a whole family is
more than one page load should carry, and a form post that sits there for a
minute looks the same as one that has died. Without JavaScript the form posts
itself and does the lot in one go, as it did before.

Existing people are matched by a previously stored GEDCOM xref first, then by
name — and
where a birth year is known on both sides it has to agree, so one person is not
overwritten by a namesake.

## Blocks

Five blocks are available, in a person's text and on the front page alike:

- **Family Tree** — a descendant outline starting from one person.
- **Family Calendar** — every date on the wiki.
- **Birthday Calendar** — the birthdays of the living.
- **Family Highlights** — the person of the hour, and the dates coming up next.
- **Recently Updated** — the pages that changed last.

## Static archives

With [Static Archive](https://github.com/akirk/static-archive) installed, people
are offered alongside posts and pages and are archived the way the app renders
them: the infobox first, then the wiki text with its links resolved. The styles
and the infobox toggle are inlined, so an archived page stands on its own.

The Markdown output lays the same facts out as a list rather than passing the
infobox through the HTML converter, which would run every label into its value.

Static Archive files non-page post types as `<year>/<post-type>-<id>`, so people
are archived by ID rather than by name. Changing that needs a filter in Static
Archive itself.

## Cross-wiki links

On WordPress multisite you can link related family wikis from the same network
in *Settings → Familypedia*. When the same person exists on both, the infobox
shows an *Also on* row; when a local link is missing, a peer wiki is checked
before it is marked red. Add a slug mapping when the two wikis use different
slugs for the same person.

## Relationship to Family Wiki

The two plugins share most of their logic, and Familypedia's GEDCOM handling,
calendar, infobox, biography, tree and static-archive support are ports of
Family Wiki's. Both are GPL-2.0-or-later.

Two bugs were fixed along the way and are worth carrying back:

- The exporter wrote one family record for a couple's marriage and another for
  the same couple as a child's parents. Re-importing then overwrote the dated
  marriage with the undated one.
- The exporter handed out fresh `I1, I2…` xrefs instead of writing back the xref
  each person was imported with, so re-importing its own export created a second
  copy of everyone who had come from an earlier GEDCOM.

They are not meant to run on the same site: Family Wiki keeps people in pages,
Familypedia keeps them in its own post type, and nothing migrates between the
two automatically. A GEDCOM export from one imports into the other.
