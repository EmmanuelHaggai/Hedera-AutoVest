import gsap from 'gsap';

const get_height = (element, selector) => {
    let els = element.querySelectorAll(selector);
    if (els.length < 1) return 0;
    return Array.from(els).reduce((sum, el) => sum + el.offsetHeight, 0);
};

const nav_anims = {
    get_offset: (menu) => {
        let width = menu.offsetWidth;
        let padding = window.getComputedStyle(menu).getPropertyValue('padding-right');
        return width + parseFloat(padding);
    },
    show_dropdown: (target) => {
        let nav_wrapper = target.closest('.navigation-menu-wrapper');
        let parent_menu = target.closest('ul');
        let current_menu = target.closest('.menu-item-has-children').querySelector('.sub-menu');

        gsap.to(parent_menu, {
            x: nav_anims.get_offset(parent_menu) * -1,
            autoAlpha: 1,
        });

        gsap.to(parent_menu.querySelectorAll(':scope > li > a'), {
            autoAlpha: 0,
        });

        gsap.to([current_menu, current_menu.querySelectorAll(':scope > li > a')], {
            autoAlpha: 1,
        });

        gsap.to(nav_wrapper, {
            height: get_height(current_menu, ':scope > li'),
        });
    },
    hide_dropdown: (target) => {
        let nav_wrapper = target.closest('.navigation-menu-wrapper');
        let current_menu = target.closest('ul');
        let parent_menu = current_menu.parentNode.parentNode.closest('ul');

        gsap.to(parent_menu, {
            x: 0,
            autoAlpha: 1,
        });

        gsap.to(parent_menu.querySelectorAll(':scope > li > a'), {
            autoAlpha: 1,
        });

        gsap.to([current_menu, current_menu.querySelectorAll(':scope > li > a')], {
            autoAlpha: 0,
        });

        gsap.to(nav_wrapper, {
            height: get_height(parent_menu, ':scope > li'),
        });
    },
};

const create_back_element = () => {
    let element = document.createElement('li');
    element.className = 'back-button';

    let child = document.createElement('a');
    child.href = '#';
    child.innerHTML =
        '<div class="icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 512"><path d="M219.9 266.7L75.89 426.7c-5.906 6.562-16.03 7.094-22.59 1.188c-6.918-6.271-6.783-16.39-1.188-22.62L186.5 256L52.11 106.7C46.23 100.1 46.75 90.04 53.29 84.1C59.86 78.2 69.98 78.73 75.89 85.29l144 159.1C225.4 251.4 225.4 260.6 219.9 266.7z"/></svg></div>' +
        'Back';

    element.appendChild(child);

    return element;
};

export const create_back_element_wrapper = () => {
    let sub_menus = document.querySelectorAll('.navigation-menu.mobile .sub-menu');
    if (sub_menus.length < 1) return;
    sub_menus.forEach((sub_menu) => {
        sub_menu.prepend(create_back_element());
    });
};

export const mob_dropdown_handler = (e, back = false) => {
    e.preventDefault();
    if (back) {
        nav_anims.hide_dropdown(e.target);
    } else {
        nav_anims.show_dropdown(e.target);
    }
};

export const mob_menu_scroll = () => {
    let menus = document.querySelectorAll('.navigation-menu.mobile');
    if (menus.length < 1) return;
    menus.forEach((menu) => {
        menu.parentNode.scrollbar = OverlayScrollbars(menu.parentNode, {
            overflowBehavior: {
                x: 'hidden',
                y: 'scroll',
            },
        });
    });
};
