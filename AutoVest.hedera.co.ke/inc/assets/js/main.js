'use strict';
import 'bootstrap';
import { accordion_class_handler } from './accordion';
import {
    nav_handler,
    dropdown_switch,
    dropdown_leave,
    sm_mouseleave_handler,
    stickyNavigationHandler,
    hamburger_handler,
} from './nav.js';
import { mob_dropdown_handler } from './mobile-nav.js';

import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import { initializeScroll } from './scroll.js';

new WOW().init();
// Load GSAP and ScrollTrigger
gsap.registerPlugin(ScrollTrigger);

function initializeDarkModeToggle() {
    const checkbox = document.getElementById('checkbox');
    if (!checkbox) return;
    checkbox.addEventListener('change', () => {
        document.body.classList.toggle('dark-mode');
    });
}

gsap.utils.toArray('.circle').forEach((circle) => {
    gsap.to(circle, {
        x: gsap.utils.random(-40, 40),
        y: gsap.utils.random(-40, 40),
        scale: gsap.utils.random(0.9, 1.1),
        rotation: gsap.utils.random(-180, 180),
        repeat: -1,
        yoyo: true,
        duration: gsap.utils.random(3, 6),
        ease: 'power1.inOut',
    });
});

function initializeCounter() {
    const statItems = document.querySelectorAll('.stat-item h2');

    // Function to format numbers as "k" or "m"
    const formatNumber = (number) => {
        const floored = Math.floor(number);
        if (floored >= 1_000_000) {
            return (floored / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'm';
        } else if (floored >= 1_000) {
            return (floored / 1_000).toFixed(1).replace(/\.0$/, '') + 'k';
        } else if (floored >= 10 && floored < 100) {
            return floored + '+';
        } else {
            return floored.toString();
        }
    };

    // Function to animate numbers with increased duration
    const animateNumbers = (element) => {
        const target = +element.getAttribute('data-target');
        const duration = 3000;
        const increment = target / (duration / 16);
        let current = 0;
        const updateNumber = () => {
            current += increment;
            if (current >= target) {
                element.textContent = formatNumber(target);
            } else {
                element.textContent = formatNumber(current);
                requestAnimationFrame(updateNumber);
            }
        };

        updateNumber();
    };

    // Intersection Observer to trigger animation on scroll
    const observer = new IntersectionObserver(
        (entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const element = entry.target;
                    animateNumbers(element);
                    observer.unobserve(element);
                }
            });
        },
        { threshold: 0.5 }
    );

    // Observe each stat item
    statItems.forEach((item) => {
        item.textContent = '0';
        observer.observe(item);
    });
}
// Consolidated Video Iframe Initialization Function
function initializeVideoModal() {
    const videoTrigger = document.getElementById('videoTrigger');
    const modal = document.getElementById('videoModal');
    const iframe = document.getElementById('videoIframe');
    const closeButton = modal ? modal.querySelector('.close-btn') : null;

    function openModal() {
        if (iframe) {
            iframe.src = 'https://www.youtube.com/embed/';
        }
        if (modal) {
            modal.style.display = 'block';
        }
    }

    function closeModal() {
        if (modal) {
            modal.style.display = 'none';
        }
        if (iframe) {
            iframe.src = '';
        }
    }

    // Add event listener to video trigger
    if (videoTrigger) {
        videoTrigger.addEventListener('click', function (e) {
            e.preventDefault();
            openModal();
        });
    } else {
    }

    // Add event listener to close button
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    } else {
    }

    if (modal) {
        window.addEventListener('click', function (event) {
            if (event.target == modal) {
                closeModal();
            }
        });
    } else {
        // console.warn('Modal not found on this page.');
    }
}
// Email-Validation
(function () {
    const submitBtn = document.getElementById('submit-btn');

    // Check if the submit button exists on the page
    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            const emailInput = document.getElementById('emailInput');
            const emailValue = emailInput.value;
            const errorMessage = document.getElementById('error-message');
            const successMessage = document.getElementById('success-message');

            // Simple email validation regex
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

            if (emailPattern.test(emailValue)) {
                errorMessage.style.display = 'none';
                successMessage.textContent = 'Thankyou for your subscribing!';
            } else {
                errorMessage.textContent = 'Please enter a valid email address.';
                errorMessage.style.visibility = 'visible';
            }
        });
    } else {
        console.warn('Submit button not available on this page.');
    }
})();
function initVideoToggle() {
    const videoContainer = document.getElementById('videoContainer');
    const videoFrame = document.getElementById('videoFrame');

    if (videoContainer && videoFrame) {
        videoContainer.addEventListener('click', function () {
            videoContainer.style.display = 'none';
            videoFrame.classList.remove('hidden');
            videoFrame.classList.add('visible');
        });
    }
}
function animateValue(el, start, end, duration, direction) {
    let startTime = null;
    const step = (timestamp) => {
        if (!startTime) startTime = timestamp;
        const progress = Math.min((timestamp - startTime) / duration, 1);
        const current = start + (end - start) * progress;

        if (progress < 1) {
            el.textContent = current.toFixed(2);
            requestAnimationFrame(step);
        } else {
            el.textContent = end.toFixed(2);
            startTicking(el, direction);
        }
    };
    requestAnimationFrame(step);
}
function startTicking(el, direction) {
    let currentValue = parseFloat(el.textContent);
    setInterval(() => {
        const variation = Math.random() * 0.05;
        currentValue += direction * variation;
        currentValue = Math.max(currentValue, 0); // prevent negative
        el.textContent = currentValue.toFixed(2);
    }, 1000);
}

// function initializeAnimatedValues() {
//     document.querySelectorAll('.item strong[data-value]').forEach((el) => {
//         const endValue = parseFloat(el.getAttribute('data-value'));
//         if (!isNaN(endValue)) {
//             const direction = Math.random() < 0.5 ? -1 : 1; // random: up or down
//             animateValue(el, 0, endValue, 1000, direction);
//         }
//     });
//     document.querySelectorAll('.card-footer span[data-value]').forEach((el) => {
//         const endValue = parseFloat(el.getAttribute('data-value'));
//         if (!isNaN(endValue)) {
//             const direction = Math.random() < 0.5 ? -1 : 1; // random: up or down
//             animateValue(el, 0, endValue, 1000, direction);
//         }
//     });
// }

// Update 2
function initializeAnimatedValues() {
  const elements = document.querySelectorAll('.item strong[data-value], .card-footer span[data-value]');
  
  elements.forEach(el => {
    const baseValue = parseFloat(el.getAttribute('data-value'));
    if (isNaN(baseValue)) return;

    // Function to randomly fluctuate around the real value
    function fluctuate() {
      const jitter = (Math.random() - 0.5) * 0.04; // ±0.02 range
      const newValue = baseValue + jitter;

      el.textContent = newValue.toFixed(2);

      // Schedule next small variation (every 1.5–2.5 seconds)
      const nextDelay = 1500 + Math.random() * 1000;
      setTimeout(fluctuate, nextDelay);
    }

    fluctuate(); // Start fluctuation loop
  });
}

function animateProgressBar(selector, width) {
    const progressBar = document.querySelector(selector);
    if (progressBar) {
        progressBar.style.width = width;
    }
}

function initializeServicesOnScroll() {
    const progressBar = document.querySelector('.progress-bar');
    if (!progressBar) return;

    const observer = new IntersectionObserver(
        (entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    animateProgressBar('.progress-bar', '35%');
                    observer.unobserve(entry.target); // run only once
                }
            });
        },
        {
            threshold: 0.5, // 50% of the element must be visible
        }
    );

    observer.observe(progressBar);
}

// Swiper initialization service
function initializeRoadmapSwiper() {
    if (typeof Swiper !== 'undefined') {
        const roadmapSwiper = new Swiper('.roadmap-swiper', {
            initialSlide: 4,
            slidesPerView: 1,
            navigation: {
                nextEl: '.roadmap-button-next',
                prevEl: '.roadmap-button-prev',
            },
            pagination: {
                el: '.roadmap-swiper-pagination',
                clickable: true,
            },
            lazy: true,
            speed: 1000,
            breakpoints: {
                768: {
                    slidesPerView: 2,
                    spaceBetween: 30,
                },
                991: {
                    slidesPerView: 3,
                    spaceBetween: 30,
                },
                1200: {
                    slidesPerView: 4,
                    spaceBetween: 30,
                },
                1280: {
                    slidesPerView: 4,
                    spaceBetween: 30,
                },
            },
        });
    }
}
// Swiper initialization service
function initializeEventSwiper() {
    if (typeof Swiper !== 'undefined') {
        const eventSwiper = new Swiper('.event-swiper', {
            loop: true,
            pagination: {
                el: '.event-swiper-pagination',
                clickable: true,
            },
        });
    }
}
// Swiper initialization service
function initializeEventFeaturedSwiper() {
    if (typeof Swiper !== 'undefined') {
        const eventFeaturedSwiper = new Swiper('.event-featured-swiper', {
            loop: true,
            pagination: {
                el: '.event-feature-swiper-pagination',
                clickable: true,
            },
        });
    }
}

function initializeIsotopeGallery() {
    if (typeof Isotope === 'undefined') {
        // console.warn('Isotope is not available on this page.');
        return;
    }

    const galleryElem = document.querySelector('.gallery');
    if (!galleryElem) return;

    let iso = new Isotope(galleryElem, {
        itemSelector: '.gallery-item',
        layoutMode: 'fitRows',
    });

    const loadMoreButton = document.getElementById('load-more');

    if (loadMoreButton) {
        loadMoreButton.addEventListener('click', (event) => {
            event.preventDefault();
            let revealedCount = 0;
            const hiddenItems = document.querySelectorAll('.gallery-item.hidden');

            hiddenItems.forEach((item) => {
                if (item.classList.contains('hidden') && revealedCount < 3) {
                    item.classList.remove('hidden');
                    revealedCount++;
                }
            });

            iso.layout();

            if (document.querySelectorAll('.gallery-item.hidden').length === 0) {
                loadMoreButton.style.display = 'none';
            }
        });
    }

    const filtersElem = document.querySelector('.filters-button-group');
    if (filtersElem) {
        filtersElem.addEventListener('click', function (event) {
            if (!matchesSelector(event.target, 'button')) return;
            const filterValue = event.target.getAttribute('data-filter');
            iso.arrange({ filter: filterValue });
        });
    }

    const buttonGroups = document.querySelectorAll('.button-group');
    buttonGroups.forEach((buttonGroup) => radioButtonGroup(buttonGroup));

    function radioButtonGroup(buttonGroup) {
        buttonGroup.addEventListener('click', function (event) {
            if (!matchesSelector(event.target, 'button')) return;
            const current = buttonGroup.querySelector('.is-checked');
            if (current) current.classList.remove('is-checked');
            event.target.classList.add('is-checked');
        });
    }

    function matchesSelector(element, selector) {
        return (
            element.matches(selector) || element.webkitMatchesSelector(selector) || element.msMatchesSelector(selector)
        );
    }
}

function initializeCountdown() {
    const countdownTarget = new Date();
    countdownTarget.setDate(countdownTarget.getDate() + 33); // 33 days from now

    function updateCountdown() {
        const now = new Date();
        const diff = countdownTarget - now;

        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        const hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
        const minutes = Math.floor((diff / (1000 * 60)) % 60);
        const seconds = Math.floor((diff / 1000) % 60);

        // ✅ Only update if element exists
        const daysEl = document.getElementById('days');
        const hoursEl = document.getElementById('hours');
        const minutesEl = document.getElementById('minutes');
        const secondsEl = document.getElementById('seconds');

        if (daysEl && hoursEl && minutesEl && secondsEl) {
            daysEl.textContent = String(days).padStart(2, '0');
            hoursEl.textContent = String(hours).padStart(2, '0');
            minutesEl.textContent = String(minutes).padStart(2, '0');
            secondsEl.textContent = String(seconds).padStart(2, '0');
        }
    }

    setInterval(updateCountdown, 1000);
    updateCountdown(); // initial call
}
// Function to handle "Load More" button clicks
function handleLoadMoreClick(target) {
    if (target.classList.contains('load-more-btn')) {
        event.preventDefault();

        const hiddenItems = document.querySelectorAll('.hidden-item');

        if (hiddenItems.length > 0) {
            hiddenItems.forEach((item) => {
                item.classList.add('visible');
            });

            target.style.display = 'none';
        }
    }
}
function initContactFormValidation() {
    const form = document.getElementById('contactForm');
    const messageBox = document.getElementById('formMessage');

    if (!form) return; // Safely exit if the form doesn't exist

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const name = form.name.value.trim();
        const email = form.email.value.trim();
        const phone = form.phone.value.trim();
        const subject = form.subject.value.trim();
        const message = form.message.value.trim();

        let errors = [];

        if (name.length < 2) errors.push('Name must be at least 2 characters.');
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.push('Enter a valid email.');
        if (!/^[0-9\-\+\s\(\)]+$/.test(phone) || phone.length < 7) errors.push('Enter a valid phone number.');
        if (subject.length < 3) errors.push('Subject must be at least 3 characters.');
        if (message.length < 10) errors.push('Message must be at least 10 characters.');

        if (errors.length > 0) {
            messageBox.style.color = 'red';
            messageBox.innerHTML = errors.join('<br>');
        } else {
            messageBox.style.color = 'green';
            messageBox.innerHTML = 'Form submitted successfully!';
        }
    });
}

function initializeCountdownTick() {
    const tickElements = document.querySelectorAll('.bitsecure-tick');

    tickElements.forEach((tickElement) => {
        if (typeof Tick !== 'undefined') {
            const tick = Tick.DOM.create(tickElement);
            setupTickCountdown(tick);
        }
    });
}

function setupTickCountdown(tick) {
    if (!tick) return;

    const now = new Date();
    const totalSeconds = 32 * 24 * 60 * 60 + 23 * 60 * 60 + 59 * 60 + 60;
    const targetDate = new Date(now.getTime() + totalSeconds * 1000);
    const isoString = targetDate.toISOString();

    const counter = Tick.count.down(isoString);

    if (tick && counter) {
        counter.onupdate = function (value) {
            tick.value = value;
        };

        counter.onended = function () {
            const message = document.querySelector('.tick-onended-message');
            if (message) message.style.display = '';
        };
    }
}
// Chart.js reusable line chart initializer
function initializeLineChart(chartId, xValues, yValues, options = {}) {
    const ctx = document.getElementById(chartId);
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: xValues,
            datasets: [
                {
                    fill: false,
                    lineTension: 0,
                    backgroundColor: options.backgroundColor || 'rgba(0,0,255,1.0)',
                    borderColor: options.borderColor || 'rgba(0,0,255,0.1)',
                    data: yValues,
                },
            ],
        },
        options: {
            legend: { display: false },
            scales: {
                yAxes: [
                    {
                        ticks: options.yTicks || { min: 0, max: 15 },
                    },
                ],
                xAxes: [
                    {
                        ticks: options.xTicks || { min: 2008, max: 2013 },
                    },
                ],
            },
            ...options.chartOptions,
        },
    });
}

// Chart 1
initializeLineChart('myChart1', [2008, 2009, 2010, 2011, 2012, 2013], [1, 5, 7, 14, 17], {
    backgroundColor: '#2196f3',
    borderColor: '#2196f3',
    yTicks: { min: 0, max: 20, stepSize: 5 },
    xTicks: { min: 2008, max: 2013, stepSize: 1 },
    chartOptions: {
        elements: {
            point: {
                radius: 6,
                backgroundColor: '#2196f3',
                borderWidth: 0,
            },
            line: {
                borderWidth: 3,
                borderColor: '#2196f3',
            },
        },
        legend: { display: false },
        tooltips: {
            enabled: true,
            mode: 'index',
            intersect: false,
            displayColors: false,
            backgroundColor: '#fff',
            titleFontColor: '#2196f3',
            bodyFontColor: '#333',
            borderColor: 'rgba(0,0,0,0)',
            borderWidth: 0,
        },
        responsive: true,
        maintainAspectRatio: false,
        layout: {
            padding: {
                left: 0,
                right: 0,
                top: 0,
                bottom: 0,
            },
        },
        scales: {
            yAxes: [
                {
                    gridLines: {
                        drawBorder: false,
                        color: '#e0e0e0',
                        zeroLineColor: '#e0e0e0',
                        borderDash: [0, 0],
                        drawOnChartArea: true,
                        drawTicks: false,
                    },
                    ticks: {
                        min: 0,
                        max: 20,
                        stepSize: 5,
                        beginAtZero: true,
                        padding: 10,
                    },
                },
            ],
            xAxes: [
                {
                    gridLines: {
                        drawBorder: false,
                        color: '#e0e0e0',
                        zeroLineColor: '#e0e0e0',
                        borderDash: [0, 0],
                        drawOnChartArea: true,
                        drawTicks: false,
                    },
                    ticks: {
                        min: 2008,
                        max: 2013,
                        stepSize: 1,
                        padding: 10,
                    },
                },
            ],
        },
    },
});
// Chart 2
initializeLineChart('myChart2', [2008, 2009, 2010, 2011, 2012, 2013], [3, 7, 5, 9, 10, 18], {
    backgroundColor: '#2196f3',
    borderColor: '#2196f3',
    yTicks: { min: 0, max: 20, stepSize: 5, beginAtZero: true },
    xTicks: { min: 2008, max: 2013, stepSize: 1 },
    chartOptions: {
        elements: {
            point: {
                radius: 6,
                backgroundColor: '#2196f3',
                borderWidth: 0,
            },
            line: {
                borderWidth: 3,
                borderColor: '#2196f3',
            },
        },
        legend: { display: false },
        tooltips: {
            enabled: true,
            mode: 'index',
            intersect: false,
            displayColors: false,
            backgroundColor: '#fff',
            titleFontColor: '#2196f3',
            bodyFontColor: '#333',
            borderColor: 'rgba(0,0,0,0)',
            borderWidth: 0,
        },
        responsive: true,
        maintainAspectRatio: false,
        layout: {
            padding: {
                left: 0,
                right: 0,
                top: 0,
                bottom: 0,
            },
        },
        scales: {
            yAxes: [
                {
                    gridLines: {
                        drawBorder: false,
                        color: '#e0e0e0',
                        zeroLineColor: '#e0e0e0',
                        borderDash: [0, 0],
                        drawOnChartArea: true,
                        drawTicks: false,
                    },
                    ticks: {
                        min: 0,
                        max: 20,
                        stepSize: 5,
                        beginAtZero: true,
                        padding: 10,
                    },
                },
            ],
            xAxes: [
                {
                    gridLines: {
                        drawBorder: false,
                        color: '#e0e0e0',
                        zeroLineColor: '#e0e0e0',
                        borderDash: [0, 0],
                        drawOnChartArea: true,
                        drawTicks: false,
                    },
                    ticks: {
                        min: 2008,
                        max: 2013,
                        stepSize: 1,
                        padding: 10,
                    },
                },
            ],
        },
    },
});
// Chart 3
initializeLineChart('myChart3', [2008, 2009, 2010, 2011, 2012, 2013], [13, 7, 8, 6, 9, 14], {
    backgroundColor: '#2196f3',
    borderColor: '#2196f3',
    yTicks: { min: 0, max: 20, stepSize: 5 },
    xTicks: { min: 2008, max: 2013, stepSize: 1 },
    chartOptions: {
        elements: {
            point: {
                radius: 6,
                backgroundColor: '#2196f3',
                borderWidth: 0,
            },
            line: {
                borderWidth: 3,
                borderColor: '#2196f3',
            },
        },
        legend: { display: false },
        tooltips: {
            enabled: true,
            mode: 'index',
            intersect: false,
            displayColors: false,
            backgroundColor: '#fff',
            titleFontColor: '#2196f3',
            bodyFontColor: '#333',
            borderColor: 'rgba(0,0,0,0)',
            borderWidth: 0,
        },
        responsive: true,
        maintainAspectRatio: false,
        layout: {
            padding: {
                left: 0,
                right: 0,
                top: 0,
                bottom: 0,
            },
        },
        scales: {
            yAxes: [
                {
                    gridLines: {
                        drawBorder: false,
                        color: '#e0e0e0',
                        zeroLineColor: '#e0e0e0',
                        borderDash: [0, 0],
                        drawOnChartArea: true,
                        drawTicks: false,
                    },
                    ticks: {
                        min: 0,
                        max: 20,
                        stepSize: 5,
                        beginAtZero: true,
                        padding: 10,
                    },
                },
            ],
            xAxes: [
                {
                    gridLines: {
                        drawBorder: false,
                        color: '#e0e0e0',
                        zeroLineColor: '#e0e0e0',
                        borderDash: [0, 0],
                        drawOnChartArea: true,
                        drawTicks: false,
                    },
                    ticks: {
                        min: 2008,
                        max: 2013,
                        stepSize: 1,
                        padding: 10,
                    },
                },
            ],
        },
    },
});
function initializeFeedbackSwiper() {
    if (typeof Swiper !== 'undefined') {
        new Swiper('.feedback-swiper', {
            loop: false,
            pagination: {
                el: '.swiper-feedback-pagination',
                clickable: true,
            },
            slidesPerView: 3,
            spaceBetween: 30,
            autoHeight: true,
            breakpoints: {
                320: {
                    slidesPerView: 1,
                    spaceBetween: 10,
                },
                480: {
                    slidesPerView: 1,
                    spaceBetween: 15,
                },
                768: {
                    slidesPerView: 2,
                    spaceBetween: 20,
                },
                992: {
                    slidesPerView: 3,
                    spaceBetween: 30,
                },
            },
        });
    }
}
// Chart.js setup
function initializeXRPChart(canvasId) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: ['', '', '', '', '', '', ''],
            datasets: [
                {
                    data: [0.5, 0.6, 0.8, 0.7, 1.0, 1.3, 1.2],
                    borderColor: '#3B82F6',
                    backgroundColor: 'transparent',
                    tension: 1,
                    fill: false,
                    pointRadius: 0,
                    borderWidth: 4,
                },
            ],
        },
        options: {
            scales: {
                xAxes: [
                    {
                        display: false,
                    },
                ],
                yAxes: [
                    {
                        display: false,
                    },
                ],
            },
            legend: {
                display: false, // Optional: hide the legend
            },
        },
    });
}
// Usage after DOM is ready:
initializeXRPChart('xrpChart1');
initializeXRPChart('xrpChart2');
initializeXRPChart('xrpChart3');
initializeXRPChart('xrpChart4');


function animateTickerValueDataTarget(element, duration = 1200, decimals = 2, bandCents = 2) {
  if (!element) return;

  const base = parseFloat(element.getAttribute('data-target'));
  if (isNaN(base)) return;

  // Clear any previous timer bound to this element
  if (element.__danceTimer) {
    clearTimeout(element.__danceTimer);
    element.__danceTimer = null;
  }

  // Smooth intro animation from 0 → base
  const startTime = performance.now();
  function animateUp(now) {
    const t = Math.min((now - startTime) / duration, 1);
    const val = base * t;
    element.textContent = val.toFixed(decimals);
    if (t < 1) requestAnimationFrame(animateUp);
    else {
      element.textContent = base.toFixed(decimals);
      startDance();
    }
  }
  requestAnimationFrame(animateUp);

  // After reaching base, oscillate in a fixed band (±bandCents) around base.
  function startDance() {
    const cent = 1 / Math.pow(10, decimals); // e.g. 0.01 for 2 decimals
    let offsetCents = 0;                      // current offset from base in cents

    function tick() {
      // Mean-reverting step: bias movement toward 0 offset
      // 60% chance to step toward zero, else random small step or hold
      let step;
      if (Math.random() < 0.60) {
        step = Math.sign(-offsetCents); // move one cent toward center
      } else {
        // -1, 0, or +1 cent with slight preference to stay
        const choices = [-1, 0, 1];
        step = choices[Math.floor(Math.random() * choices.length)];
      }

      // Apply step and clamp to band
      offsetCents = Math.max(-bandCents, Math.min(bandCents, offsetCents + step));

      // Occasionally snap to center to avoid any bias accumulation
      if (Math.random() < 0.10) offsetCents += Math.sign(-offsetCents); // one extra nudge toward 0

      const newVal = base + offsetCents * cent;
      element.textContent = newVal.toFixed(decimals);

      // Next tick in 1.2–2.2s for natural rhythm
      const nextDelay = 1200 + Math.random() * 1000;
      element.__danceTimer = setTimeout(tick, nextDelay);
    }

    tick();
  }
}


function animateTickerValueDataTarget2(element, duration = 2000, decimals = 2, alwaysIncrease = false) {
    if (!element) return;
    const endValue = parseFloat(element.getAttribute('data-target'));
    if (isNaN(endValue)) return;
    let startValue = 0;
    let currentValue = startValue;
    const startTime = performance.now();
    function update(now) {
        const elapsed = now - startTime;
        const progress = Math.min(elapsed / duration, 1);
        currentValue = startValue + (endValue - startValue) * progress;
        element.textContent = currentValue.toFixed(decimals);
        if (progress < 1) {
            requestAnimationFrame(update);
        } else {
            element.textContent = endValue.toFixed(decimals);
            setInterval(() => {
                const variation = alwaysIncrease ? Math.abs(Math.random() * 0.1) : (Math.random() - 0.5) * 0.1;
                currentValue += variation;
                element.textContent = currentValue.toFixed(decimals);
            }, 1000);
        }
    }
    requestAnimationFrame(update);
}


document.querySelectorAll('.value[data-target]').forEach((el) => {
    animateTickerValueDataTarget(el, 2000, 4);
});
document.querySelectorAll('.card-stats strong[data-target]').forEach((el) => {
    animateTickerValueDataTarget(el, 2000, 2, true);
});
function initializeCustomDropdown(dropdownId, callback) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const dropdownButton = dropdown.querySelector('.dropdown-button');
    const dropdownMenu = dropdown.querySelector('.dropdown-menu');
    const textSpan = dropdownButton.querySelector('span');
    const imgTag = dropdownButton.querySelector('img');

    // Toggle dropdown
    dropdownButton.addEventListener('click', () => {
        dropdownMenu.style.display = dropdownMenu.style.display === 'block' ? 'none' : 'block';
    });

    // Option select
    dropdownMenu.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (!li) return;

        const value = li.getAttribute('data-value');
        const img = li.getAttribute('data-img');

        textSpan.textContent = value;
        if (imgTag) imgTag.src = `../inc/assets/images/${img}`;
        dropdownMenu.style.display = 'none';

        if (typeof callback === 'function') {
            callback(value);
        }
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
        if (!e.target.closest(`#${dropdownId}`)) {
            dropdownMenu.style.display = 'none';
        }
    });
}
// Custom dropdown logic for language switcher
function initializeLanguageDropdown() {
    const langDropdown = document.getElementById('langDropdown');
    if (!langDropdown) return; // Prevent errors if not present

    const button = langDropdown.querySelector('.dropdown-button');
    const menu = langDropdown.querySelector('.dropdown-menu');
    const selectedText = document.getElementById('langSelectedText');
    const items = menu.querySelectorAll('li');

    // Set active language on load
    const currentUrl = window.location.pathname.split('/').pop();
    let found = false;
    items.forEach(function (item) {
        if (item.getAttribute('data-url') === currentUrl) {
            selectedText.textContent = item.textContent;
            found = true;
        }
    });
    if (!found) {
        // Default to English if not found
        selectedText.textContent = 'English';
    }

    button.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.classList.toggle('show');
        langDropdown.classList.toggle('open');
    });
    items.forEach(function (item) {
        item.addEventListener('click', function () {
            selectedText.textContent = item.textContent;
            menu.classList.remove('show');
            langDropdown.classList.remove('open');
            // Redirect to selected language page
            const url = item.getAttribute('data-url');
            if (url) window.location.href = url;
        });
    });
    document.addEventListener('click', function () {
        menu.classList.remove('show');
        langDropdown.classList.remove('open');
    });
}
// ON_LOAD_SERVICES array
const ON_LOAD_SERVICES = [
    initializeDarkModeToggle,
    initializeCounter,
    initializeVideoModal,
    initializeRoadmapSwiper,
    accordion_class_handler,
    initVideoToggle,
    initializeAnimatedValues,
    initializeCountdown,
    initializeServicesOnScroll,
    initializeIsotopeGallery,
    initContactFormValidation,
    initializeEventSwiper,
    initializeEventFeaturedSwiper,
    initializeScroll,
    initializeCountdownTick,
    initializeFeedbackSwiper,
    initializeXRPChart,
    initializeCustomDropdown,
    initializeLanguageDropdown,
    initializeHeaderScroll,
];

window.addEventListener('load', (e) => {
    ON_LOAD_SERVICES.forEach((service) => service(e));
});
document.addEventListener('click', (e) => {
    // Load More Button
    if (e.target.closest('.load-more-btn')) {
        handleLoadMoreClick(e.target.closest('.load-more-btn'));
    }
});
//  SCROLL TO TOP SCRIPT
(function () {
    let scrollToTopBtn = document.querySelector('.scrollToTopBtn');
    let rootElement = document.documentElement;

    function handleScroll() {
        var scrollTotal = rootElement.scrollHeight - rootElement.clientHeight;
        if (!scrollToTopBtn) return;
        if (rootElement.scrollTop / scrollTotal > 0.15) {
            // Show button
            scrollToTopBtn.classList.add('showBtn');
        } else {
            // Hide button
            scrollToTopBtn.classList.remove('showBtn');
        }
    }

    function scrollToTop() {
        document.body.lenis.scrollTo(0, {
            duration: 2,
            easing: (x) =>
                x === 0
                    ? 0
                    : x === 1
                      ? 1
                      : x < 0.5
                        ? Math.pow(2, 20 * x - 10) / 2
                        : (2 - Math.pow(2, -20 * x + 10)) / 2,
        });
    }
    if (scrollToTopBtn) {
        scrollToTopBtn.addEventListener('click', scrollToTop);
    }
    document.addEventListener('scroll', handleScroll);
})();

const searchClickHandler = (e) => {
    const wrapper = e.target.closest('.search');
    wrapper.classList.toggle('active');
};

const searchCancelHandler = (e) => {
    const search = document.querySelector('.search.active');
    if (search == null || e.target.closest('.search')) {
        return;
    }
    search.classList.remove('active');
};

const CLICK_HANDLERS = {
    '.hamburger-icon': hamburger_handler,
    '.hamburger-overlay': hamburger_handler,
    '.hamburger-close': hamburger_handler,
    '.navigation-menu.mobile': (e) => {
        if (e.target.closest('.back-button')) {
            mob_dropdown_handler(e, true);
        }

        if (e.target.parentNode.matches('.menu-item-has-children')) {
            mob_dropdown_handler(e);
        }
    },
    '.search-icon': searchClickHandler,
    body: searchCancelHandler,
};

document.addEventListener('click', (e) => {
    for (const [key, value] of Object.entries(CLICK_HANDLERS)) {
        if (e.target.closest(key)) {
            value(e);
        }
    }
});

// mouseenter event
document.body.addEventListener(
    'mouseenter',
    function (e) {
        e.stopPropagation();

        // navigation
        if (e.target.matches('.navigation-menu.desktop > .menu-item-has-children')) {
            nav_handler(e);
        }
    },
    true
);

// mouseleave event
document.body.addEventListener(
    'mouseleave',
    function (e) {
        e.stopPropagation();

        if (e.target.matches('.navigation-menu.desktop > .menu-item-has-children')) {
            nav_handler(e);
        }

        if (e.target.matches('.navigation-menu.desktop .sub-menu .sub-menu')) {
            sm_mouseleave_handler(e);
        }
    },
    true
);

document.body.addEventListener(
    'mouseover',
    (e) => {
        e.stopPropagation();
        if (e.target.closest('.navigation-menu.desktop .sub-menu > .menu-item-has-children')) {
            dropdown_switch(e);
        }
    },
    true
);

document.body.addEventListener('mouseout', (e) => {
    if (e.target.closest('.navigation-menu.desktop .sub-menu > .menu-item-has-children')) {
        dropdown_leave(e);
    }
});

function initializeHeaderScroll() {
    window.addEventListener('scroll', function () {
        var header = document.querySelector('.header');
        if (!header) return;
        if (window.scrollY > 0) {
            header.classList.add('sticky-enabled');
        } else {
            header.classList.remove('sticky-enabled');
        }
    });
}

let selectedFrom = 'GBP - British Pound';
let selectedTo = 'BTC - Bitcoin';

initializeCustomDropdown('fromDropdown', (val) => {
    selectedFrom = val;
    updateRates();
});

initializeCustomDropdown('toDropdown', (val) => {
    selectedTo = val;
    updateRates();
});

function updateRates() {
    const from = selectedFrom.split(' ')[0];
    const to = selectedTo.split(' ')[0];
    const key = `${from}-${to}`;

    const rates = {
        'GBP-BTC': { rate: 0.0001, change: -38.445 },
        'GBP-ETH': { rate: 0.00055, change: 12.2 },
        'USD-BTC': { rate: 0.0002, change: -10.5 },
        'USD-ETH': { rate: 0.0008, change: 4.9 },
        'EUR-BTC': { rate: 0.00015, change: 8.3 },
        'GBP-USDT': { rate: 1.21, change: 0.2 },
        'USD-USDT': { rate: 1.0, change: 0 },
    };

    const info = rates[key];

    if (!info) return;

    const fromAmount = 1000;
    document.querySelector('.from-amount').textContent = fromAmount;
    document.querySelector('.to-amount').textContent = (fromAmount * info.rate).toFixed(4);
    document.querySelector('.current-rate').textContent = info.rate.toFixed(4);

    const rateChangeText = document.querySelector('.rate-change-value');
    const arrow = document.getElementById('rate-arrow');

    rateChangeText.textContent = `${Math.abs(info.rate).toFixed(4)} (${Math.abs(info.change).toFixed(2)}%)`;

    // Remove both classes first
    rateChangeText.classList.remove('green', 'red');
    arrow.classList.remove('arrow-up', 'arrow-down');

    if (info.change >= 0) {
        rateChangeText.classList.add('green');
        arrow.classList.add('arrow-up');
    } else {
        rateChangeText.classList.add('red');
        arrow.classList.add('arrow-down');
    }
}

if (document.querySelector('#particles-section')) {
    particles({
        opacity: 100,
        numParticles: 10,
        sizeMultiplier: 5,
        width: 1,
        connections: true,
        connectionDensity: 15,
        noBounceH: false,
        noBounceV: false,
        speed: 50,
        avoidMouse: true,
        hover: false,
    });
}
