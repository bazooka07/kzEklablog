// Slidehow with Eklablog

(function() {
	'use strict';

	var timer1;

	function createButton(aClassName, text) {
		const el = document.createElement('DIV');
		el.className = aClassName;
		el.innerHTML = '<span class="ob-slideshow-caret">' + text + '</span>';
		return el;
	}

	function nextItem(container, step) {
		container.slides[container.index].classList.remove('active');
		container.index = (container.index + step) % container.slides.length;
		if(container.index < 0) {
			container.index = container.slides.length - 1;
		}
		container.slides[container.index].classList.add('active');
	}

	const containers = document.querySelectorAll('.ob-slideshow');
	Array.from(containers).forEach(function(el) {
		const prevBtn = createButton('ob-slideshow-prev', '〈');
		const nextBtn = createButton('ob-slideshow-next', '〉');
		prevBtn.container = el;
		nextBtn.container = el;
		el.appendChild(prevBtn);
		el.appendChild(nextBtn);
		prevBtn.addEventListener('click', function(ev) {
			ev.preventDefault();
			clearInterval(timer1);
			nextItem(ev.currentTarget.container, -1);
		});
		nextBtn.addEventListener('click', function(ev) {
			ev.preventDefault();
			clearInterval(timer1);
			nextItem(ev.currentTarget.container, 1);
		});
		el.index = 0;
		el.slides = el.querySelectorAll('.ob-slideshow .ob-slideshow-item');

		el.slides[el.index].classList.add('active');
	});

	function start() {
		timer1 = setInterval(function() {
			Array.from(containers).forEach(function(el) {
				nextItem(el, 1);
			});
		}, 3000);
	}

	start();
})();
