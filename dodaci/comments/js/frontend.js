/* Brendy Comments — frontend JS.
 * Currently minimal; reserved for future inline-edit / voting features. */
(function () {
	'use strict';

	// If there's a flash message and no visible comments section anchor yet,
	// scroll to the comments section so the user sees the feedback immediately.
	document.addEventListener('DOMContentLoaded', function () {
		var section = document.getElementById('brendy-comments');
		if (!section) return;
		var flash = section.querySelector('.brendy-comments-flash');
		if (flash) {
			var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			section.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
		}
	});
})();
