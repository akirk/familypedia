# Familypedia

- Contributors: akirk
- Tags: family, wiki, genealogy, gedcom
- Tested up to: 6.8
- Requires PHP: 7.4
- License: [GPLv2 or later](http://www.gnu.org/licenses/gpl-2.0.html)
- Stable tag: 1.0.0

Keep your family history in a wiki app hosted on WordPress.

## Description

Familypedia is a [WpApp](https://github.com/akirk/wp-app) version of
[Family Wiki](https://github.com/akirk/family-wiki), with two deliberate
differences:

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
| `/familypedia/` | The home page: a person of the hour, and the dates coming up |
| `/familypedia/people` | Everyone, grouped by initial, with a search |
| `/familypedia/<name>` | A person, their infobox and their text |
| `/familypedia/<name>/edit` | The form for that person's facts |
| `/familypedia/<name>/<page>` | A related page beneath a person |
| `/familypedia/calendar` | The family calendar, month by month |
| `/familypedia/calendar/<month>` | One month on its own |
| `/familypedia/birthdays` | Birthdays of the living |
| `/familypedia/tree` and `/familypedia/tree/<name>` | Descendant outlines |
| `/familypedia/new` | Add a person |

The calendar and birthday pages can be switched off in *Settings → Familypedia*.

## Roles and privacy

People use WordPress's page capabilities, so *Editor* and *Administrator* can
edit them straight away. The plugin also adds *Wiki User* (can edit) and
*Wiki Editor* (can also delete).

*Settings → Reading* gains an option to make the site private, visible only to
administrators, editors and wiki users. On a wiki about living people that is
usually the option you want.

## Wiki links

Links in a person's text are resolved as the page is rendered: a link to
somebody who exists points at their page in the app, one to somebody who does
not is marked red and offers to create them, and a link that leaves the site is
marked green.

## GEDCOM import and export

Administrators import and export GEDCOM files on the app's own
*Import / Export* page. An upload opens a review step where you can pick
individual entries or whole descendant subtrees before importing. Existing
people are matched by a previously stored GEDCOM xref first, then by name — and
where a birth year is known on both sides it has to agree, so one person is not
overwritten by a namesake.

## Blocks

Three blocks are available in a person's text:

- **Family Tree** — a descendant outline starting from one person.
- **Family Calendar** — every date on the wiki.
- **Birthday Calendar** — the birthdays of the living.

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
