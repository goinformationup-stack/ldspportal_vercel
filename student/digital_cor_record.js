document.addEventListener('DOMContentLoaded', () => {
    window.corStates = {};

    const DOC_WIDTH = 794;
    const DOC_HEIGHT = 561;

    const updateTransform = (canvas, id) => {
        const state = window.corStates[id];
        if (!state || !canvas) return;

        canvas.style.transform =
            `translate(${state.tx}px, ${state.ty}px) scale(${state.scale})`;
    };

    const fitDocs = () => {
        document.querySelectorAll('.word-doc').forEach(canvas => {
            const id = canvas.id.replace('cor_document_', '');
            const container = canvas.parentElement;

            if (!container) return;

            container.style.touchAction = 'none';
            container.style.cursor = 'grab';

            if (!window.corStates[id]) {
                window.corStates[id] = {
                    scale: 1,
                    tx: 0,
                    ty: 0
                };

                setupPanZoom(container, canvas, id);
            }

            const containerWidth = container.clientWidth - 32;
            let initialScale = 1;

            if (containerWidth < DOC_WIDTH && containerWidth > 0) {
                initialScale = containerWidth / DOC_WIDTH;
            }

            if (
                window.corStates[id].scale === 1 &&
                window.corStates[id].tx === 0 &&
                window.corStates[id].ty === 0
            ) {
                window.corStates[id].scale = initialScale;
                updateTransform(canvas, id);

                const display =
                    document.getElementById('zoomLevel_' + id);

                if (display) {
                    display.innerText =
                        Math.round(initialScale * 100) + '%';
                }
            }

            container.style.height =
                (DOC_HEIGHT * window.corStates[id].scale + 48) + 'px';
        });
    };

    window.zoomCOR = function(id, increment) {
        const canvas =
            document.getElementById('cor_document_' + id);

        const display =
            document.getElementById('zoomLevel_' + id);

        const container =
            canvas ? canvas.parentElement : null;

        if (!canvas || !display || !window.corStates[id]) return;

        let newScale =
            window.corStates[id].scale + increment;

        if (newScale < 0.3) newScale = 0.3;
        if (newScale > 3.0) newScale = 3.0;

        window.corStates[id].scale = newScale;

        updateTransform(canvas, id);

        display.innerText =
            Math.round(newScale * 100) + '%';

        if (container) {
            container.style.height =
                (DOC_HEIGHT * newScale + 48) + 'px';
        }
    };

    function setupPanZoom(container, canvas, id) {
        let isDragging = false;
        let startX = 0;
        let startY = 0;
        let initialPinchDist = null;
        let initialScale = 1;

        container.addEventListener('mousedown', e => {
            isDragging = true;

            startX =
                e.clientX -
                window.corStates[id].tx;

            startY =
                e.clientY -
                window.corStates[id].ty;

            container.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', e => {
            if (!isDragging) return;

            window.corStates[id].tx =
                e.clientX - startX;

            window.corStates[id].ty =
                e.clientY - startY;

            updateTransform(canvas, id);
        });

        window.addEventListener('mouseup', () => {
            isDragging = false;
            container.style.cursor = 'grab';
        });

        window.addEventListener('mouseleave', () => {
            isDragging = false;
            container.style.cursor = 'grab';
        });

        container.addEventListener('wheel', e => {
            e.preventDefault();

            if (e.ctrlKey) {
                window.zoomCOR(
                    id,
                    e.deltaY > 0 ? -0.05 : 0.05
                );
            } else {
                window.corStates[id].tx -= e.deltaX;
                window.corStates[id].ty -= e.deltaY;

                updateTransform(canvas, id);
            }
        }, { passive: false });

        container.addEventListener('touchstart', e => {
            if (e.touches.length === 1) {
                isDragging = true;

                startX =
                    e.touches[0].clientX -
                    window.corStates[id].tx;

                startY =
                    e.touches[0].clientY -
                    window.corStates[id].ty;
            } else if (e.touches.length === 2) {
                isDragging = false;

                initialPinchDist = Math.hypot(
                    e.touches[0].clientX -
                    e.touches[1].clientX,

                    e.touches[0].clientY -
                    e.touches[1].clientY
                );

                initialScale =
                    window.corStates[id].scale;
            }
        }, { passive: false });

        container.addEventListener('touchmove', e => {
            e.preventDefault();

            if (
                e.touches.length === 1 &&
                isDragging
            ) {
                window.corStates[id].tx =
                    e.touches[0].clientX -
                    startX;

                window.corStates[id].ty =
                    e.touches[0].clientY -
                    startY;

                updateTransform(canvas, id);

            } else if (
                e.touches.length === 2 &&
                initialPinchDist
            ) {
                const currentDist = Math.hypot(
                    e.touches[0].clientX -
                    e.touches[1].clientX,

                    e.touches[0].clientY -
                    e.touches[1].clientY
                );

                let newScale =
                    initialScale *
                    (currentDist / initialPinchDist);

                if (newScale < 0.3) newScale = 0.3;
                if (newScale > 3.0) newScale = 3.0;

                window.corStates[id].scale =
                    newScale;

                updateTransform(canvas, id);

                const display =
                    document.getElementById(
                        'zoomLevel_' + id
                    );

                if (display) {
                    display.innerText =
                        Math.round(newScale * 100) + '%';
                }

                container.style.height =
                    (DOC_HEIGHT * newScale + 48) + 'px';
            }
        }, { passive: false });

        container.addEventListener('touchend', e => {
            if (e.touches.length < 2) {
                initialPinchDist = null;
            }

            if (e.touches.length === 0) {
                isDragging = false;
                container.style.cursor = 'grab';
            }
        });

        container.addEventListener('touchcancel', () => {
            isDragging = false;
            initialPinchDist = null;
            container.style.cursor = 'grab';
        });
    }

    fitDocs();

    window.addEventListener('resize', fitDocs);
});

window.viewCOR = function(id) {
    document
        .querySelectorAll('.cor-record-wrapper')
        .forEach(wrapper => {
            wrapper.classList.remove('block');
            wrapper.classList.add('hidden');
        });

    document
        .querySelectorAll('.cor-card')
        .forEach(card => {
            card.classList.remove(
                'border-[#00205b]',
                'ring-1',
                'ring-[#00205b]',
                'shadow-md'
            );

            card.classList.add(
                'border-slate-300',
                'shadow-sm'
            );

            const iconBg =
                card.querySelector('.w-8.h-8');

            if (iconBg) {
                iconBg.classList.remove(
                    'bg-[#00205b]/10',
                    'text-[#00205b]'
                );

                iconBg.classList.add(
                    'bg-slate-200',
                    'text-slate-500'
                );
            }
        });

    const targetWrapper =
        document.getElementById(
            'cor_wrapper_' + id
        );

    if (!targetWrapper) return;

    targetWrapper.classList.remove('hidden');
    targetWrapper.classList.add('block');

    const card =
        document.querySelector(
            `.cor-card[onclick*="${id}"]`
        );

    if (card) {
        card.classList.remove(
            'border-slate-300',
            'shadow-sm'
        );

        card.classList.add(
            'border-[#00205b]',
            'ring-1',
            'ring-[#00205b]',
            'shadow-md'
        );

        const iconBg =
            card.querySelector('.w-8.h-8');

        if (iconBg) {
            iconBg.classList.remove(
                'bg-slate-200',
                'text-slate-500'
            );

            iconBg.classList.add(
                'bg-[#00205b]/10',
                'text-[#00205b]'
            );
        }
    }

    if (window.innerWidth < 768) {
        setTimeout(() => {
            targetWrapper.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }, 100);
    }
};