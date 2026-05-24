<?php

namespace App\Console\Commands;

use Dcat\Admin\Models\Menu;
use Illuminate\Console\Command;

class InstallAiHistoryMenu extends Command
{
    protected $signature = 'ai-history:install-menu';

    protected $description = 'Install AI history menu item into Dcat Admin';

    public function handle()
    {
        $menu = Menu::query()->where('uri', 'ai-sessions')->first();

        if (! $menu) {
            $menu = new Menu();
            $menu->parent_id = 0;
            $menu->order = 50;
            $menu->title = 'AI 会话';
            $menu->icon = 'fa-comments';
            $menu->uri = 'ai-sessions';
            $menu->save();
            $this->info('AI history menu installed.');
            return 0;
        }

        $menu->title = 'AI 会话';
        $menu->icon = $menu->icon ?: 'fa-comments';
        $menu->save();
        $this->info('AI history menu already exists.');

        return 0;
    }
}
