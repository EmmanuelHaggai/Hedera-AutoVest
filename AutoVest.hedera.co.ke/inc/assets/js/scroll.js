import Lenis from '@studio-freight/lenis';
import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

export const add_scroll_event = (callback) => {
    if (document.body.scrollEvents === undefined) {
        document.body.scrollEvents = [];
    }

    document.body.scrollEvents.push(callback);
};

export const get_scroll_pos = () => {
    // if custom scrollbar is active
    if (document.body.scrollbar !== undefined) {
        return document.body.scrollbar.scroll().position.y;
    } else {
        return window.scrollY;
    }
};

export const initializeScroll = () => {
    const lenis = new Lenis({
        duration: 1.8,
    });

    lenis.on('scroll', ScrollTrigger.update);

    gsap.ticker.add((time) => {
        lenis.raf(time * 1000);
    });

    gsap.ticker.lagSmoothing(0);

    document.body.lenis = lenis;
};
