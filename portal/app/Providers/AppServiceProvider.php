<?php

namespace App\Providers;

use App\Models\LevelAdviserAppointment;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Public menu: CMS pages that are published and flagged for the menu, grouped.
        View::composer('layouts.public', function ($view) {
            $pages = \App\Models\Page::published()->where('show_in_menu', true)->orderBy('sort_order')->orderBy('title')->get();
            $view->with('menuGroups', $pages->whereNotNull('menu_group')->groupBy('menu_group'))
                ->with('menuPages', $pages->whereNull('menu_group'));
        });

        // Tenure alerts shown on every portal page: the HoD sees all, an adviser sees their own.
        View::composer('layouts.portal', function ($view) {
            $user = auth()->user();
            $alerts = collect();

            if ($user) {
                $query = LevelAdviserAppointment::dueForReminder()->with('user');
                if (! $user->isPrincipalAdmin()) {
                    $query->where('user_id', $user->id);
                }
                $alerts = $user->isPrincipalAdmin() || $user->role === User::ROLE_LECTURER
                    ? $query->orderBy('level')->get()
                    : collect();
            }

            $view->with('adviserAlerts', $alerts);
        });
    }
}
