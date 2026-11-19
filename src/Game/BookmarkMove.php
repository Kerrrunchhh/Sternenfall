<?php

namespace App\Game;

/** How the selected bookmarks move in the player's list. */
enum BookmarkMove: string
{
    case Top = 'top';
    case Up = 'up';
    case Down = 'down';
    case Bottom = 'bottom';
}
