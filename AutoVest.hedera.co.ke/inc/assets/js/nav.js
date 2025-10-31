import { add_scroll_event, get_scroll_pos } from './scroll';
import gsap from 'gsap';
/*
 *   nav_handler() is the entry point
 *   it calls the mouseenter and mouseleave handler according to the type of event
 */

const IS_ADMIN_BAR = document.body.classList.contains('admin-bar');
const isRTL = document.documentElement.dir === 'rtl';
const ADMIN_BAR_HEIGHT = 32;

const nav_anims = {
    is_animating: false,
    // shows specified sub-menu
    show_menu: (sub_menu) => {
        if (sub_menu === undefined || sub_menu === null) return;
        let sub_menu_lis = sub_menu.querySelectorAll(':scope > li > a');
        if (sub_menu_lis.length < 1) return;
        // bound the menu by screen
        position_menu(sub_menu);

        // show the sub_menu
        gsap.to([sub_menu, ...sub_menu_lis], {
            autoAlpha: 1,
            marginTop: 0,
            overwrite: 'auto',
            'pointer-events': 'auto',
            ease: 'power4.inOut',
            // onStart: () => {
            //     gsap.set(
            //         sub_menu,
            //         {
            //             'pointerEvents': 'auto',
            //         }
            //     );
            // }
        });
    },
    // hids specified sub-menu
    hide_menu: (sub_menu, disable_pointer_events) => {
        if (sub_menu === undefined || sub_menu === null) return;
        gsap.to(sub_menu, {
            x: 0,
            y: 0,
            marginTop: 15,
            autoAlpha: 0,
            overwrite: 'auto',
            'pointer-events': 'none',
            ease: 'power4.inOut',
            // onStart: () => {
            //     if ( !disable_pointer_events ) return;
            //     gsap.set(
            //         document.querySelectorAll('.sub-menu'),
            //         {
            //             'pointerEvents': 'none',
            //         }
            //     )
            // }
        });
    },
    // hide all anchor elements
    hide_menu_links: (els) => {
        if (els === undefined || els === null) return;
        gsap.to(els, {
            autoAlpha: 0,
            duration: 0.3,
            overwrite: 'auto',
            ease: 'power4.inOut',
        });
    },
    // hides background box
    hide_bg_box: (y) => {
        let bg = document.querySelector('.nav-dropdown-bg');
        let icon = document.querySelector('.nav-dropdown-bg-icon');
        let coords = get_custom_bounding_rect(bg);
        // bg.isAnimating = false;
        if (coords == null) return;
        gsap.to(icon, {
            autoAlpha: 0,
            ease: 'power4.inOut',
            overwrite: 'auto',
            onComplete: () => {
                if (icon === undefined || icon === null) return;
                if (icon.parentNode === undefined || icon.parentNode === null) return;
                // delete the background box
                icon.parentNode.removeChild(icon);
            },
        });

        gsap.to(bg, {
            autoAlpha: 0,
            y: coords.y + 50,
            // top: 15,
            ease: 'power4.inOut',
            overwrite: 'auto',
            onComplete: () => {
                if (bg === undefined || bg === null) return;
                if (bg.parentNode === undefined || bg.parentNode === null) return;
                // delete the background box
                bg.parentNode.removeChild(bg);
                // bg.parentNode.removeChild(icon);
            },
            onUpdateParams: [bg, false],
            onUpdate: position_icon,
        });
    },
};

// accomodates for the custom scrollbar and returns correct
// coordinates of the elements
const get_custom_bounding_rect = (el) => {
    if (el === undefined || el === null) return;
    let coords = el.getBoundingClientRect();
    return coords;
};

const create_bg_arrow = () => {
    let div = document.createElement('div');
    div.className = 'icon';
    div.innerHTML =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M9.39 265.4l127.1-128C143.6 131.1 151.8 128 160 128s16.38 3.125 22.63 9.375l127.1 128c9.156 9.156 11.9 22.91 6.943 34.88S300.9 320 287.1 320H32.01c-12.94 0-24.62-7.781-29.58-19.75S.2333 274.5 9.39 265.4z"/></svg>';
    return div;
};

const add_bg_arrow = () => {
    let icon = create_bg_arrow();
    icon.classList.add('nav-dropdown-bg-icon');
    document.body.appendChild(icon);
    return icon;
};

const position_icon = (bg, create = true) => {
    let icon = document.querySelector('.nav-dropdown-bg-icon');
    if (icon == null && create) icon = add_bg_arrow();
    if (bg == null || icon == null) return;

    let coords = get_custom_bounding_rect(bg);

    if (IS_ADMIN_BAR) {
        coords.y -= ADMIN_BAR_HEIGHT;
    }

    gsap.set(icon, {
        x: coords.x,
        y: coords.y,
    });

    return icon;
};

// positions the background box
const position_nav_bg = (element, coords, extra, animate = true, opacity = {}) => {
    if (element === undefined || element === null) return;
    // options for the gsap animation
    const options = {
        y: coords.top,
        x: coords.left,
        width: coords.width,
        height: coords.height,
        overwrite: 'auto',
        ...extra,
    };

    if (isRTL) {
        options.x = (document.documentElement.clientWidth - options.x - options.width) * -1;
    }

    if (IS_ADMIN_BAR) {
        options.y -= ADMIN_BAR_HEIGHT;
    }

    if (animate) {
        gsap.to(element, {
            ...options,
            ...opacity,
            ease: 'power4.inOut',
            onUpdateParams: [element],
            onUpdate: position_icon,
        });

        if (opacity.length) {
            let icon = document.querySelector('.nav-dropdown-bg-icon');
            gsap.to(icon, {
                ...opacity,
                ease: 'power4.inOut',
                overwrite: 'auto',
            });
        }
    } else {
        gsap.set(element, options);
    }
};

const position_bg_icon = (element, distance) => {
    if (element == null) return;

    gsap.to(element, {
        left: distance,
        overwrite: 'auto',
    });
};

// creates the background box
const create_nav_bg_element = (sub_menu, options = null) => {
    if (sub_menu === undefined || sub_menu === null) return;

    // create element and add class
    let div = document.createElement('div');
    div.className = 'nav-dropdown-bg';

    let coords = get_custom_bounding_rect(sub_menu);
    position_nav_bg(div, coords, options, false);

    // div.appendChild( create_bg_arrow() );

    // add the background box to DOM
    document.body.appendChild(div);

    return div;
};

// returns the farthest top-level sub-menu
const farthest_parent = (el) => {
    if (el === undefined || el === null) return;
    // select the navigation
    let nav = document.querySelector('.navigation-menu');
    if (nav === undefined || nav === null) return;
    // if the parent of the element is navigation then select the sub-menu and return it
    if (el.parentNode === nav) return el.querySelector('.sub-menu');

    // select the parent sub-menu
    let parent = el.closest('.sub-menu');

    // if the parent is not null and parent sub-men and current element are not the same
    if (parent !== null && parent !== el) {
        return farthest_parent(parent);
    }
    // if the parent is not null and parent sub-men and current element are the same
    else if (parent !== null && parent === el) {
        return farthest_parent(parent.parentNode);
    } else {
        return parent;
    }
};

// changes the border-radius when inner-submenu are shown
const change_border_radius = (menu, side, change_bg_radius = false) => {
    if (menu == null) return;
    let parent = menu.parentNode.closest('.sub-menu');
    let bg = document.querySelector('.nav-dropdown-bg');

    if (side === 'reset') {
        let radius = parseFloat(
            window.getComputedStyle(document.querySelector(':root')).getPropertyValue('--border-radius')
        );

        gsap.to([menu, parent, bg], {
            'border-top-left-radius': radius,
            'border-top-right-radius': radius,
            'border-bottom-left-radius': radius,
            'border-bottom-right-radius': radius,
            onComplete: () => {
                if (menu.matches('.sub-menu .sub-menu')) {
                    menu.style.setProperty('left', '100%');
                    menu.style.setProperty('right', 'initial');
                }
            },
        });

        return;
    }

    let parent_anim = {
        [`border-top-${side === 'left' ? 'right' : 'left'}-radius`]: 0,
        [`border-bottom-${side === 'left' ? 'right' : 'left'}-radius`]: 0,
        overwrite: true,
    };

    gsap.to(menu, {
        [`border-top-${side}-radius`]: 0,
        [`border-bottom-${side}-radius`]: 0,
        overwrite: true,
    });

    gsap.to(parent, parent_anim);

    if (change_bg_radius) {
        gsap.to(bg, parent_anim);
    }
};

// positions the sub-menu so that it always stays within the screen limits
const position_menu = (menu) => {
    if (menu === undefined || menu === null) return;
    // check the coords of the menu
    let coords = get_custom_bounding_rect(menu);
    // if the coords overflow
    let window_width = window.innerWidth;
    // make sure the menu appears within the site's width and height
    let is_toplevel = menu.matches('.navigation-menu > li > .sub-menu > li > .sub-menu');
    let is_true_toplevel = menu.matches('.navigation-menu > li > .sub-menu');

    if (coords.right > window_width && is_true_toplevel) {
        menu.style.setProperty('left', coords.right - window_width - 25 + 'px');
        return;
    }

    if (coords.right > window_width) {
        menu.style.setProperty('left', 'initial');
        menu.style.setProperty('right', '100%');
        // if ( menu.matches('.sub-menu .sub-menu') ) {
        //     change_border_radius( menu, 'right', is_toplevel );
        // }
    } else if (menu.matches('.sub-menu .sub-menu')) {
        // change_border_radius( menu, 'left', is_toplevel );
    }
};

// handles the mouseenter event
const mouseenter_handler = (e) => {
    // select the target li element
    let el = e.srcElement;
    // get the coords of the target li
    let el_coords = get_custom_bounding_rect(el);

    // select the sub-menu
    let sub_menu = farthest_parent(el);
    // show the menu
    nav_anims.show_menu(sub_menu);

    // get coordinates of the sub-menu
    let coords = get_custom_bounding_rect(sub_menu);
    // select bg element
    let bg = document.querySelector('.nav-dropdown-bg');
    let icon = document.querySelector('.nav-dropdown-bg-icon');
    // if the background box does not exist then create it
    if (bg === null || bg === undefined) {
        bg = create_nav_bg_element(sub_menu);
        icon = position_icon(bg);

        gsap.set([bg, icon], {
            y: el_coords.bottom + 50,
        });

        gsap.to(icon, {
            autoAlpha: 1,
            ease: 'power4.inOut',
        });

        let yPosition = el_coords.bottom;

        if (IS_ADMIN_BAR) {
            yPosition -= ADMIN_BAR_HEIGHT;
        }

        gsap.to(bg, {
            autoAlpha: 1,
            y: yPosition,
            ease: 'power4.inOut',
            onUpdateParams: [bg],
            onUpdate: position_icon,
        });
    } else {
        gsap.to(icon, {
            autoAlpha: 1,
            ease: 'power4.inOut',
            overwrite: 'auto',
        });
        // position the bg element
        position_nav_bg(bg, coords, { x: el_coords.left - 25, y: el_coords.bottom }, true, { autoAlpha: 1 });
    }
    // 15 is the padding of the anchor element. it is used here to get the correct center
    position_bg_icon(bg.querySelector('.icon'), (el_coords.right - el_coords.left + 15) / 2);
};

// handles the mouseleave event
const mouseleave_handler = (e) => {
    // select the sub-menu
    let sub_menu = e.target.querySelectorAll('.sub-menu');
    // select all anchor elements in all sub-menus
    let lis = e.target.querySelectorAll(':scope .sub-menu li a');
    nav_anims.is_animating = true;

    // hide the anchor elements
    // duration should match the duration used for hiding the parent sub-menu anchor elements
    nav_anims.hide_menu_links(lis);

    // hide the sub-menus
    nav_anims.hide_menu(sub_menu);

    // reset the border-radius of the sub-menus
    // sub_menu.forEach((menu) => {
    //     change_border_radius(menu, 'reset');
    // });

    // select the bg element
    nav_anims.hide_bg_box();
};

// main entry point for top level animations
export const nav_handler = (e) => {
    // select the navigation menu
    let nav = document.querySelector('.navigation-menu');

    // if the navigation does not exist then return
    if (nav === undefined || nav === null) return;

    // only procede if it is first level of depth
    // if ( e.target.parentNode !== nav ) return;

    // call appropriate function based on the type of event
    // we only want to execute the mouseenter handler for the top level sub-menus
    // inner sub-menus are handled using the mouseover event.
    if (e.type === 'mouseenter' && e.target.parentNode === nav) mouseenter_handler(e);
    else if (e.type === 'mouseleave') mouseleave_handler(e);
};

// sm stands for 'sub_menu'
export const sm_mouseleave_handler = (e) => {
    // select the active sub-menu
    let sub_menu = e.target;
    // change_border_radius(sub_menu, 'reset');
    // hide menu
    nav_anims.hide_menu(sub_menu, false);
};

export const dropdown_leave = (e) => {
    if (e.target.parentNode.contains(e.toElement)) {
        return;
    }
    // select the active sub-menu
    let sub_menu = e.target.parentNode.querySelector('.sub-menu');
    // change_border_radius(sub_menu, 'reset');
    // hide menu
    nav_anims.hide_menu(sub_menu, false);
};

export const dropdown_switch = (e) => {
    // select the active sub-menu
    let sub_menu = e.target.parentNode.querySelector('.sub-menu');
    // show menu
    nav_anims.show_menu(sub_menu);
};

export const stickyNavigationHandler = () => {
    let headers = document.querySelectorAll('.sticky-nav');
    if (headers.length < 1) return;
    let y_pos = get_scroll_pos();

    headers.forEach((header) => {
        let coords = header.getBoundingClientRect();
        if (y_pos - 30 > coords.top) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
};

export const hamburger_handler = (e) => {
    let el = e.target.closest('.hamburger');
    el.classList.toggle('shown');
    document.querySelector('body.custom-scrollbar > .os-scrollbar-vertical')?.classList?.toggle('hamburger-shown');
};

add_scroll_event(stickyNavigationHandler);
