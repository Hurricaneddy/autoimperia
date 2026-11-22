<?php

function uiIcon(string $name): string
{
    $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return '<svg class="ui-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><use href="'
        . BASE_URL . '/assets/icons.svg#' . $name . '"></use></svg>';
}

function serviceCategoryIcon(int $categoryId): string
{
    return [1 => 'oil', 2 => 'search', 3 => 'brake', 4 => 'settings', 5 => 'ruler', 6 => 'wheel', 7 => 'bolt'][$categoryId] ?? 'tool';
}
