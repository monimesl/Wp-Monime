/**
 * Monime WooCommerce Blocks Integration
 */
(function (wcBlocksRegistry, wpElement, wpHtmlEntities, wcSettings) {
    'use strict';

    const { registerPaymentMethod } = wcBlocksRegistry;
    const { createElement, useEffect } = wpElement;
    const { decodeEntities } = wpHtmlEntities;

    const settings = wcSettings.getSetting('monime_data', {});

    const Content = () => {
        // Add CSS for hover effect
        useEffect(() => {
            const styleId = 'monime-blocks-styles';
            if (!document.getElementById(styleId)) {
                const style = document.createElement('style');
                style.id = styleId;
                style.innerHTML = `
                    .monime-overflow-container:hover .monime-overflow-popover {
                        opacity: 1 !important;
                        visibility: visible !important;
                        pointer-events: auto !important;
                    }
                `;
                document.head.appendChild(style);
            }
        }, []);

        return createElement(
            'div',
            { className: 'monime-gateway-content' },
            createElement('p', {}, decodeEntities(settings.description || '')),
            createElement('div', {
                className: 'monime-provider-icons',
                dangerouslySetInnerHTML: { __html: settings.html || '' }
            })
        );
    };

    const Label = ({ components }) => {
        const title = decodeEntities(settings.title || 'Monime Checkout');
        return createElement(
            'span',
            { style: { display: 'flex', alignItems: 'center', gap: '8px' } },
            settings.icon ? createElement('img', { src: settings.icon, alt: 'Monime', style: { width: '24px', height: '24px' } }) : null,
            components && components.PaymentMethodLabel 
                ? createElement(components.PaymentMethodLabel, { text: title })
                : title
        );
    };

    registerPaymentMethod({
        name: 'monime',
        label: createElement(Label, {}),
        content: createElement(Content, {}),
        edit: createElement(Content, {}),
        canMakePayment: () => true,
        ariaLabel: decodeEntities(settings.title || 'Monime Checkout'),
        supports: {
            features: settings.supports || ['products'],
        },
    });

})( 
    window.wc.wcBlocksRegistry, 
    window.wp.element, 
    window.wp.htmlEntities, 
    window.wc.wcSettings 
);;
