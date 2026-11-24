(function (window, document) {
	'use strict';

	function escapeRegExp(value) {
		return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function highlightSearchResults() {
		var queryNode = document.querySelector('.search-results-header .search-query');
		if (!queryNode) return;
		var searchTerm = String(queryNode.textContent || '').trim();
		if (!searchTerm) return;
		var seenWords = Object.create(null);
		var words = searchTerm.toLowerCase().split(/\s+/).filter(function (word) {
			if (!word || seenWords[word]) return false;
			seenWords[word] = true;
			return true;
		}).slice(0, 8);
		if (!words.length) return;
		var pattern = new RegExp('(' + words.map(escapeRegExp).join('|') + ')', 'gi');
		var roots = document.querySelectorAll('article, main, [role="main"], .content');
		var seen = new Set();
		roots.forEach(function (root) {
			if (seen.has(root) || root.closest('.search-results-header, form') ||
				root.parentElement && root.parentElement.closest('article, main, [role="main"], .content')) return;
			seen.add(root);
			var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
			var textNodes = [];
			while (walker.nextNode()) {
				var node = walker.currentNode;
				var parent = node.parentElement;
				if (!parent || parent.closest('.search-results-header, form, script, style, template, noscript, mark, [hidden], [aria-hidden="true"], [data-no-search-highlight]')) continue;
				textNodes.push(node);
			}
			textNodes.forEach(function (textNode) {
				var text = textNode.nodeValue || '';
				pattern.lastIndex = 0;
				if (!pattern.test(text)) return;
				pattern.lastIndex = 0;
				var fragment = document.createDocumentFragment();
				var match;
				var lastIndex = 0;
				while ((match = pattern.exec(text)) !== null) {
					fragment.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
					var mark = document.createElement('mark');
					mark.className = 'search-highlight';
					mark.textContent = match[0];
					fragment.appendChild(mark);
					lastIndex = pattern.lastIndex;
				}
				fragment.appendChild(document.createTextNode(text.slice(lastIndex)));
				if (textNode.parentNode) textNode.parentNode.replaceChild(fragment, textNode);
			});
		});
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', highlightSearchResults, { once: true });
	else highlightSearchResults();
}(window, document));
