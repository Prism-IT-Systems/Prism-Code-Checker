<?php
function prism_theme_fixture() {
    return get_option('blogname');
}
function prism_theme_broken() {
    return totally_missing_theme_function();
}
