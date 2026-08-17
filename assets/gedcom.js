/**
 * Choosing what to take out of an uploaded GEDCOM.
 *
 * The table lists everyone in the file; the tree below it draws the branches
 * that were ticked, so that people picked by the subtree button and never
 * looked at can be dropped again before the import runs.
 */
(function () {
	var review = document.querySelector('.familypedia-gedcom-review');
	if (!review) {
		return;
	}

	var body = review.querySelector('tbody');
	var counter = review.querySelector('[data-familypedia-gedcom-count]');
	var countTemplate = counter ? counter.textContent.trim() : '';
	var rows = Array.prototype.slice.call(review.querySelectorAll('[data-familypedia-gedcom-row]'));
	var view = 'connected';
	var needle = '';
	var sortKey = '';
	var sortDescending = true;

	var tree = JSON.parse(document.getElementById('familypedia-gedcom-tree-data').textContent);
	var treeList = review.querySelector('[data-familypedia-gedcom-tree-list]');
	var treeEmpty = review.querySelector('[data-familypedia-gedcom-tree-empty]');
	var treeMore = review.querySelector('[data-familypedia-gedcom-tree-more]');
	var moreTemplate = treeMore.textContent.trim();
	var treeBox = review.querySelector('.familypedia-gedcom-tree');
	var settings = window.familypediaGedcom || {};
	var l10n = settings.l10n || {};
	var dropLabel = l10n.drop;
	var branchLabel = l10n.dropBranch;
	var toggleLabel = l10n.toggle;
	// Branches are drawn open, so the selection reads as the outline the
	// wiki will show once it is imported. Folding is remembered here, not
	// in the drawing, which starts over on every tick: a branch folded
	// away stays folded while the selection changes.
	var folded = Object.create(null);
	// Far past what anyone reads. Drawing every node of a whole-file
	// selection would only make ticking a box slow.
	var TREE_LIMIT = 400;

	// Xrefs come out of the file, so they are never spliced into a
	// selector: every lookup goes through these maps instead.
	var boxes = Object.create(null);
	rows.forEach(function (row) {
		var box = boxOf(row);
		if (box) {
			boxes[box.value] = box;
		}
	});

	var parentsOf = Object.create(null);
	Object.keys(tree).forEach(function (xref) {
		(tree[xref].children || []).forEach(function (child) {
			parentsOf[child] = parentsOf[child] || [];
			parentsOf[child].push(xref);
		});
	});

	function boxOf(row) {
		return row.querySelector('[data-familypedia-gedcom-person]');
	}

	function visible(row) {
		return row.style.display !== 'none';
	}

	function apply() {
		rows.forEach(function (row) {
			var inView = view === 'all' || row.hasAttribute('data-connected');
			var matches = !needle || row.getAttribute('data-name').indexOf(needle) !== -1;
			row.style.display = inView && matches ? '' : 'none';
		});
	}

	function refreshCount() {
		if (!counter) {
			return;
		}
		var selected = rows.filter(function (row) {
			var box = boxOf(row);
			return box && box.checked;
		}).length;
		// Replace the leading number in the rendered, translated string.
		counter.textContent = countTemplate.replace(/\d+/, String(selected));
	}

	function refresh() {
		refreshCount();
		renderTree();
	}

	function person(xref) {
		return tree[xref] || { name: xref };
	}

	function yearRange(entry) {
		if (entry.birth && entry.death) {
			return entry.birth + '–' + entry.death;
		}
		if (entry.birth) {
			return '*' + entry.birth;
		}
		if (entry.death) {
			return '†' + entry.death;
		}

		return '';
	}

	function nameOf(xref) {
		var entry = person(xref);
		var node = document.createElement('span');
		node.setAttribute('data-familypedia-gedcom-node', xref);
		node.appendChild(document.createTextNode(entry.name));

		// Written the way the wiki's own tree writes it: the space stays
		// outside the span, which does not wrap.
		var years = yearRange(entry);
		if (years) {
			var dates = document.createElement('span');
			dates.className = 'familypedia-tree__years';
			dates.textContent = '(' + years + ')';
			node.appendChild(document.createTextNode(' '));
			node.appendChild(dates);
		}

		return node;
	}

	function byBirth(a, b) {
		// Undated people sort last rather than first, where a missing
		// year would read as the oldest child of every household.
		var left = person(a).birth || '9999';
		var right = person(b).birth || '9999';
		if (left === right) {
			return person(a).name.localeCompare(person(b).name);
		}

		return left < right ? -1 : 1;
	}

	function householdChildren(xref, partners) {
		var seen = Object.create(null);
		var children = [];
		[xref].concat(partners).forEach(function (parent) {
			(person(parent).children || []).forEach(function (child) {
				if (!seen[child]) {
					seen[child] = true;
					children.push(child);
				}
			});
		});

		return children;
	}

	/**
	 * Who a line's drop button takes away: the branch hanging under
	 * it, and only once nothing hangs there the couple on the line
	 * itself. Taking the line along with its branch would carry off
	 * people who married in, and with them whichever of their own
	 * brothers and sisters were drawn beneath them.
	 */
	function dropTargets(item) {
		var branch = item.querySelector('ul');

		return (branch || item.querySelector('.familypedia-gedcom-tree__line'))
			.querySelectorAll('[data-familypedia-gedcom-node]');
	}

	function toggleFor(xref, list) {
		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'familypedia-gedcom-tree__mark familypedia-gedcom-tree__toggle';
		toggle.setAttribute('data-familypedia-gedcom-toggle', xref);
		toggle.setAttribute('aria-label', toggleLabel);
		setOpen(toggle, list, !folded[xref]);

		return toggle;
	}

	function fold(toggle) {
		var xref = toggle.getAttribute('data-familypedia-gedcom-toggle');
		folded[xref] = !folded[xref];
		setOpen(toggle, toggle.closest('li').querySelector('ul'), !folded[xref]);
	}

	function setOpen(toggle, list, open) {
		// The people below stay in the page while folded away: the
		// count on the drop button, and what it drops, are the branch
		// itself and not the part of it that happens to be in view.
		list.hidden = !open;
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		toggle.textContent = open ? '▾' : '▸';
	}

	function renderPerson(xref, state) {
		if (state.drawn[xref] || state.left < 1) {
			return null;
		}
		state.drawn[xref] = true;
		state.left -= 1;

		var item = document.createElement('li');
		var line = document.createElement('div');
		line.className = 'familypedia-gedcom-tree__line';
		line.appendChild(nameOf(xref));

		// The people they married, on the same line: a couple splits
		// their children between them, so drawing them apart would
		// show every household twice with half a family each.
		var partners = (person(xref).partners || []).filter(function (partner) {
			return state.selected[partner] && !state.drawn[partner];
		});
		// The same mark the wiki's own tree puts between a couple: every
		// family record this comes from becomes a marriage on import.
		partners.forEach(function (partner) {
			state.drawn[partner] = true;
			state.left -= 1;
			line.appendChild(document.createTextNode(' ⚭ '));
			line.appendChild(nameOf(partner));
		});

		item.appendChild(line);

		var children = householdChildren(xref, partners).filter(function (child) {
			return state.selected[child] && !state.drawn[child];
		}).sort(byBirth);

		if (children.length) {
			var list = document.createElement('ul');
			children.forEach(function (child) {
				var childItem = renderPerson(child, state);
				if (childItem) {
					list.appendChild(childItem);
				}
			});
			if (list.childNodes.length) {
				item.appendChild(list);
				line.insertBefore(toggleFor(xref, list), line.firstChild);
			}
		}

		// A line with nobody under it still gives up the width, so the
		// names down a generation stay under one another.
		if (!item.querySelector('ul')) {
			var spacer = document.createElement('span');
			spacer.className = 'familypedia-gedcom-tree__mark';
			spacer.setAttribute('aria-hidden', 'true');
			line.insertBefore(spacer, line.firstChild);
		}

		// Only now is it known whether anyone hangs off this line, which
		// is what the button offers to take away.
		var branch = item.querySelector('ul');
		if (branch) {
			line.classList.add('familypedia-gedcom-tree__line--branch');
		}

		var drop = document.createElement('button');
		drop.type = 'button';
		drop.className = 'familypedia-gedcom-tree__drop';
		drop.setAttribute('data-familypedia-gedcom-drop', '');
		drop.textContent = (branch ? branchLabel : dropLabel)
			+ ' (' + dropTargets(item).length + ')';
		line.appendChild(drop);

		return item;
	}

	function renderTree() {
		var state = {
			selected: Object.create(null),
			drawn: Object.create(null),
			left: TREE_LIMIT
		};
		var total = 0;
		rows.forEach(function (row) {
			var box = boxOf(row);
			if (box && box.checked) {
				state.selected[box.value] = true;
				total += 1;
			}
		});

		treeList.textContent = '';
		treeEmpty.hidden = total > 0;
		treeMore.hidden = true;
		if (!total) {
			return;
		}

		// Start from everyone whose parents are staying behind. Those
		// are the tops of the branches that were picked, which is not
		// where the file itself starts.
		Object.keys(state.selected).filter(function (xref) {
			return (parentsOf[xref] || []).every(function (parent) {
				return !state.selected[parent];
			});
		}).sort(byBirth).forEach(function (xref) {
			var item = renderPerson(xref, state);
			if (item) {
				treeList.appendChild(item);
			}
		});

		// Whatever the branches did not reach still has to show up, or
		// people would be imported that the tree never accounted for.
		Object.keys(state.selected).forEach(function (xref) {
			var item = renderPerson(xref, state);
			if (item) {
				treeList.appendChild(item);
			}
		});

		var missing = total - Object.keys(state.drawn).length;
		treeMore.hidden = missing < 1;
		if (missing > 0) {
			treeMore.textContent = moreTemplate.replace(/\d+/, String(missing));
		}
	}

	function sort(key) {
		if (sortKey === key) {
			sortDescending = !sortDescending;
		} else {
			sortKey = key;
			sortDescending = true;
		}
		var attribute = key === 'wiki' ? 'data-wiki' : 'data-subtree';
		rows.sort(function (a, b) {
			var difference = parseInt(a.getAttribute(attribute), 10) - parseInt(b.getAttribute(attribute), 10);
			return sortDescending ? -difference : difference;
		});
		rows.forEach(function (row) {
			body.appendChild(row);
		});
	}

	review.addEventListener('click', function (event) {
		var viewButton = event.target.closest('[data-familypedia-gedcom-view]');
		if (viewButton) {
			view = viewButton.getAttribute('data-familypedia-gedcom-view');
			review.querySelectorAll('[data-familypedia-gedcom-view]').forEach(function (button) {
				button.classList.toggle('familypedia-button--primary', button === viewButton);
			});
			apply();
			return;
		}

		var sortLink = event.target.closest('[data-familypedia-gedcom-sort]');
		if (sortLink) {
			event.preventDefault();
			sort(sortLink.getAttribute('data-familypedia-gedcom-sort'));
			return;
		}

		var selectAll = event.target.closest('[data-familypedia-gedcom-select-all]');
		var clear = event.target.closest('[data-familypedia-gedcom-clear]');
		if (selectAll || clear) {
			rows.forEach(function (row) {
				var box = boxOf(row);
				if (!box) {
					return;
				}
				// "Select everyone shown" respects the current view and filter.
				if (clear) {
					box.checked = false;
				} else if (visible(row)) {
					box.checked = true;
				}
			});
			refresh();
			return;
		}

		var descendants = event.target.closest('[data-familypedia-gedcom-descendants]');
		if (descendants) {
			descendants.getAttribute('data-familypedia-gedcom-descendants').split(',').forEach(function (xref) {
				if (boxes[xref]) {
					boxes[xref].checked = true;
				}
			});
			refresh();
			return;
		}

		// Folding changes nothing about the selection, so it moves the
		// one branch rather than drawing the whole tree again.
		var toggle = event.target.closest('[data-familypedia-gedcom-toggle]');
		if (toggle) {
			fold(toggle);
			return;
		}

		// The names are a far bigger target than the arrow beside
		// them, and fold the same branch. On a line with nobody
		// underneath there is nothing to fold, so they do nothing.
		var named = event.target.closest('[data-familypedia-gedcom-node]');
		if (named) {
			var owner = named.closest('li').querySelector('[data-familypedia-gedcom-toggle]');
			if (owner) {
				fold(owner);
			}
			return;
		}

		var drop = event.target.closest('[data-familypedia-gedcom-drop]');
		if (drop) {
			dropTargets(drop.closest('li')).forEach(function (node) {
				var xref = node.getAttribute('data-familypedia-gedcom-node');
				if (boxes[xref]) {
					boxes[xref].checked = false;
				}
			});
			refresh();
			return;
		}

		if (event.target.closest('[data-familypedia-gedcom-person]')) {
			refresh();
		}
	});

	review.addEventListener('input', function (event) {
		if (!event.target.closest('[data-familypedia-gedcom-filter]')) {
			return;
		}
		needle = event.target.value.trim().toLowerCase();
		apply();
	});

	/*
	 * Enter in the filter field would submit the form through its first button,
	 * which is the one that imports the whole file. Filtering is not asking for
	 * that.
	 */
	review.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && event.target.closest('[data-familypedia-gedcom-filter]')) {
			event.preventDefault();
		}
	});

	/*
	 * Importing, a batch at a time.
	 *
	 * A whole family is more work than one request should carry, and a form post
	 * that sits there for a minute looks the same as one that has died. The same
	 * file is walked in pieces instead, and each piece says how far it has got.
	 * Without fetch the form posts itself, which still works.
	 */
	var form = review.querySelector('[data-familypedia-gedcom-form]');
	var progress = review.querySelector('[data-familypedia-gedcom-progress]');
	var bar = progress ? progress.querySelector('[data-familypedia-gedcom-progress-bar]') : null;
	var text = progress ? progress.querySelector('[data-familypedia-gedcom-progress-text]') : null;
	// event.submitter is not everywhere yet, and which button was pressed is the
	// difference between the whole file and a selection.
	var pressed = null;

	if (form && progress && settings.endpoint && window.fetch) {
		form.addEventListener('click', function (event) {
			var button = event.target.closest('button[type="submit"]');
			if (button) {
				pressed = button;
			}
		});

		form.addEventListener('submit', function (event) {
			var submitter = event.submitter || pressed;
			var all = !!(submitter && submitter.name === 'familypedia_import_all');
			var selected = all ? null : Array.prototype.slice
				.call(form.querySelectorAll('[data-familypedia-gedcom-person]:checked'))
				.map(function (box) {
					return box.value;
				});

			// Nothing ticked is a mistake the server already words well, and
			// letting the form post keeps that wording in one place.
			if (selected && !selected.length) {
				return;
			}

			event.preventDefault();
			start(selected, treeWanted());
		});
	}

	function treeWanted() {
		var box = form.querySelector('[name="familypedia_front_page_tree"]');

		return !!(box && box.checked);
	}

	function send(url, payload) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce },
			body: JSON.stringify(payload || {})
		}).then(function (response) {
			return response.json().then(function (data) {
				if (!response.ok) {
					throw new Error(data && data.message ? data.message : response.statusText);
				}

				return data;
			});
		});
	}

	function start(selected, tree) {
		progress.hidden = false;
		progress.classList.remove('familypedia-gedcom-progress--failed');
		working(true);
		say(l10n.starting, 0);

		send(settings.endpoint, { token: settings.token, selected: selected, front_page_tree: tree })
			.then(function (started) {
				return step(started.run);
			})
			.catch(failed);
	}

	function step(run) {
		return send(settings.endpoint + '/' + run).then(function (state) {
			if (state.done) {
				say(state.message, 100);
				window.location = state.redirect;
				return;
			}

			say(
				(state.stage === 'families' ? l10n.families : l10n.people)
					.replace('%1$s', state.position)
					.replace('%2$s', state.total),
				state.total ? Math.round((state.position / state.total) * 100) : 0
			);

			return step(run);
		});
	}

	function say(message, percent) {
		text.textContent = message;
		bar.value = percent;
	}

	function failed(error) {
		say(l10n.failed.replace('%s', error.message), 0);
		progress.classList.add('familypedia-gedcom-progress--failed');
		working(false);
	}

	/**
	 * While a run is going, the buttons that would start a second one are out of
	 * reach: the file is walked by cursor, and two walks would import it twice.
	 */
	function working(busy) {
		Array.prototype.slice.call(form.querySelectorAll('button, input')).forEach(function (control) {
			control.disabled = busy;
		});
	}

	apply();
	refresh();
}());
