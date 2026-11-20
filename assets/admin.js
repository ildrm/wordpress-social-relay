(function () {
    'use strict';

    const catalog = document.querySelector('[data-sr-catalog]');
    if (catalog) {
        const search = catalog.querySelector('[data-sr-network-search]');
        const cards = Array.from(catalog.querySelectorAll('[data-sr-network]'));
        const empty = catalog.querySelector('[data-sr-network-empty]');
        catalog.querySelector('[data-sr-search-wrapper]').hidden = false;
        function filterCatalog() {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            cards.forEach(function (card) {
                card.hidden = !card.dataset.srNetwork.toLocaleLowerCase().includes(query);
                if (!card.hidden) {
                    visible += 1;
                }
            });
            empty.hidden = visible !== 0;
        }
        search.addEventListener('input', filterCatalog);
        window.addEventListener('pageshow', filterCatalog);
    }

    const recipe = document.querySelector('.sr-recipe-form');
    if (recipe) {
        const template = recipe.querySelector('[data-sr-template]');
        const destination = recipe.querySelector('[data-sr-destination]');
        const tokens = recipe.querySelector('[data-sr-tokens]');
        const starterButton = recipe.querySelector('[data-sr-use-template]');
        const starter = () => destination.selectedOptions[0].dataset.template;
        let previousStarter = starter();
        tokens.hidden = false;
        starterButton.hidden = false;

        destination.addEventListener('change', function () {
            const currentStarter = starter();
            if (template.value === previousStarter) {
                template.value = currentStarter;
            }
            previousStarter = currentStarter;
        });
        starterButton.addEventListener('click', function () {
            template.value = starter();
            template.focus();
        });
        tokens.querySelectorAll('[data-sr-token]').forEach(function (token) {
            token.addEventListener('click', function () {
                template.setRangeText(token.dataset.srToken, template.selectionStart, template.selectionEnd, 'end');
                template.focus();
            });
        });
    }

    document.querySelectorAll('[data-sr-copy]').forEach(function (button) {
        const text = document.getElementById(button.dataset.srCopy);
        const output = button.closest('.sr-manual').querySelector('[data-sr-copy-status]');
        if (!text || !navigator.clipboard) {
            return;
        }
        button.hidden = false;
        button.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(text.value);
                output.textContent = button.dataset.copySuccess;
            } catch (error) {
                text.focus();
                text.select();
                output.textContent = button.dataset.copyFailure;
            }
        });
    });
}());
