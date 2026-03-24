const class_handler = (e) => {
    if (e.type === 'show.bs.collapse') {
        e.target.closest('.accordion-item').classList.add('shown');
    } else {
        e.target.closest('.accordion-item').classList.remove('shown');
    }
};

export const accordion_class_handler = () => {
    let items = document.querySelectorAll('.accordion-collapse');
    items.forEach((item) => {
        item.addEventListener('show.bs.collapse', class_handler);
        item.addEventListener('hide.bs.collapse', class_handler);
    });
};
