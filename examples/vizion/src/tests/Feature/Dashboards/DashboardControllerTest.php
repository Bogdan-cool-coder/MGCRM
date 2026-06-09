<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for DashboardController:
 *   - index visibility matrix (system / published / personal × roles)
 *   - show with embedded pivot widgets + layout
 *   - create / update / delete (owner, admin, viewer-403); system reject (O3)
 *   - attach / detach widget, layout batch
 *   - clone of a system dashboard => personal copy with pivot links (O3)
 *   - cross-company isolation
 *   - /data stub
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function makeUser(Company $company, string $role): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    // -------------------------------------------------------------------------
    // index — visibility matrix
    // -------------------------------------------------------------------------

    /** @test */
    public function test_index_admin_sees_system_and_company_dashboards(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $system   = Dashboard::factory()->system()->create(['company_id' => $company->id]);
        $personal = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson('/api/dashboards');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($personal->id, $ids);
    }

    /** @test */
    public function test_index_viewer_sees_only_system_and_published(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');

        $system   = Dashboard::factory()->system()->create(['company_id' => $company->id]);
        $personal = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $pub      = Dashboard::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson('/api/dashboards');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($pub->id, $ids);
        $this->assertNotContains($personal->id, $ids);
    }

    /** @test */
    public function test_index_analyst_does_not_see_another_users_private_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $other   = $this->makeUser($company, 'analyst');

        $othersPrivate = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);

        $response = $this->actingAs($analyst)->getJson('/api/dashboards');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertNotContains($othersPrivate->id, $ids);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    /** @test */
    public function test_show_returns_dashboard_with_pivot_widgets(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard->widgets()->attach($widget->id, [
            'x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'sort' => 5, 'visible' => true,
        ]);

        $response = $this->actingAs($admin)->getJson("/api/dashboards/{$dashboard->id}");
        $response->assertOk();
        $response->assertJsonPath('id', $dashboard->id);
        $response->assertJsonPath('widgets.0.id', $widget->id);
        $response->assertJsonPath('widgets.0.pivot.x', 1);
        $response->assertJsonPath('widgets.0.pivot.y', 2);
        $response->assertJsonPath('widgets.0.pivot.w', 3);
        $response->assertJsonPath('widgets.0.pivot.h', 4);
        $response->assertJsonPath('widgets.0.pivot.sort', 5);
        $response->assertJsonPath('widgets.0.pivot.visible', true);

        // Action-menu contract: show() must expose ownership/audit fields so
        // the frontend can render the actions menu (author / created_at /
        // is_system / is_published / user_id).
        $response->assertJsonStructure([
            'id', 'name', 'is_system', 'is_published', 'user_id',
            'created_at', 'updated_at',
            'author' => ['id', 'name', 'email'],
        ]);
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('is_published', false);
        $response->assertJsonPath('user_id', $admin->id);
        $response->assertJsonPath('author.id', $admin->id);
        $response->assertJsonPath('author.email', $admin->email);
    }

    /** @test */
    public function test_viewer_cannot_show_unpublished_personal_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson("/api/dashboards/{$dashboard->id}");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_cannot_show_cross_company_dashboard(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA   = $this->makeUser($companyA, 'admin');
        $dashB    = Dashboard::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $response = $this->actingAs($adminA)->getJson("/api/dashboards/{$dashB->id}");
        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // create / update / delete
    // -------------------------------------------------------------------------

    /** @test */
    public function test_analyst_can_create_empty_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($analyst)->postJson('/api/dashboards', [
            'name' => ['ru' => 'Мой', 'en' => 'Mine'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('user_id', $analyst->id);
        $response->assertJsonPath('widgets', []);
    }

    /** @test */
    public function test_viewer_cannot_create_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->postJson('/api/dashboards', [
            'name' => ['en' => 'X'],
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function test_owner_can_rename_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->putJson("/api/dashboards/{$dashboard->id}", [
            'name' => ['en' => 'Renamed'],
        ]);
        $response->assertOk();
        $this->assertSame('Renamed', $dashboard->fresh()->getTranslation('name', 'en'));
    }

    /** @test */
    public function test_update_rejects_system_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $dashboard = Dashboard::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->putJson("/api/dashboards/{$dashboard->id}", [
            'name' => ['en' => 'Nope'],
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function test_owner_can_delete_dashboard_without_touching_widgets(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($analyst)->deleteJson("/api/dashboards/{$dashboard->id}");
        $response->assertOk();
        $this->assertNull(Dashboard::find($dashboard->id));
        // Pivot cascades, but the widget entity survives.
        $this->assertNotNull(Widget::find($widget->id), 'shared widget must survive dashboard delete');
        $this->assertDatabaseMissing('dashboard_widget', ['dashboard_id' => $dashboard->id]);
    }

    /** @test */
    public function test_delete_rejects_system_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $dashboard = Dashboard::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->deleteJson("/api/dashboards/{$dashboard->id}");
        $response->assertStatus(403);
        $this->assertNotNull(Dashboard::find($dashboard->id));
    }

    /** @test */
    public function test_viewer_cannot_delete_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->deleteJson("/api/dashboards/{$dashboard->id}");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_delete_cascades_pinned_dashboard_mini_chat(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $chat = Chat::create([
            'user_id'      => $analyst->id,
            'company_id'   => $company->id,
            'type'         => 'quick_qa',
            'scope_type'   => Chat::SCOPE_DASHBOARD,
            'dashboard_id' => $dashboard->id,
        ]);

        $response = $this->actingAs($analyst)->deleteJson("/api/dashboards/{$dashboard->id}");
        $response->assertOk();
        $this->assertNull(Dashboard::find($dashboard->id));
        $this->assertNull(Chat::find($chat->id), 'pinned scope=dashboard mini-chat must cascade, not orphan');
    }

    /** @test */
    public function test_delete_does_not_touch_chats_anchored_to_other_dashboards(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $target = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $other  = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $otherChat = Chat::create([
            'user_id'      => $analyst->id,
            'company_id'   => $company->id,
            'type'         => 'quick_qa',
            'scope_type'   => Chat::SCOPE_DASHBOARD,
            'dashboard_id' => $other->id,
        ]);

        $this->actingAs($analyst)->deleteJson("/api/dashboards/{$target->id}")->assertOk();

        // The other dashboard's mini-chat is untouched.
        $this->assertNotNull(Chat::find($otherChat->id));
        $this->assertNotNull(Dashboard::find($other->id));
    }

    // -------------------------------------------------------------------------
    // publish / unpublish
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_publish_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->postJson("/api/dashboards/{$dashboard->id}/publish");
        $response->assertOk();
        $response->assertJsonPath('is_published', true);
        $this->assertTrue((bool) Dashboard::find($dashboard->id)->is_published);
    }

    /** @test */
    public function test_superadmin_can_publish_cross_company_dashboard(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $superadmin = $this->makeUser($companyA, 'superadmin');
        $dashboard = Dashboard::factory()->create(['company_id' => $companyB->id]);

        $response = $this->actingAs($superadmin)->postJson("/api/dashboards/{$dashboard->id}/publish");
        $response->assertOk();
        $response->assertJsonPath('is_published', true);
    }

    /** @test */
    public function test_admin_can_unpublish_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $dashboard = Dashboard::factory()->published()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->postJson("/api/dashboards/{$dashboard->id}/unpublish");
        $response->assertOk();
        $response->assertJsonPath('is_published', false);
        $this->assertFalse((bool) Dashboard::find($dashboard->id)->is_published);
    }

    /** @test */
    public function test_analyst_cannot_publish_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$dashboard->id}/publish");
        $response->assertStatus(403);
        $this->assertFalse((bool) Dashboard::find($dashboard->id)->is_published);
    }

    /** @test */
    public function test_viewer_cannot_publish_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->postJson("/api/dashboards/{$dashboard->id}/unpublish");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_publish_rejects_system_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $dashboard = Dashboard::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->postJson("/api/dashboards/{$dashboard->id}/publish");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_cannot_publish_dashboard_from_another_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $admin = $this->makeUser($companyA, 'admin');
        $dashboard = Dashboard::factory()->create(['company_id' => $companyB->id]);

        $response = $this->actingAs($admin)->postJson("/api/dashboards/{$dashboard->id}/publish");
        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // attach / detach
    // -------------------------------------------------------------------------

    /** @test */
    public function test_owner_can_attach_widget(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $widget->id,
            'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('widgets.0.id', $widget->id);
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $dashboard->id,
            'widget_id'    => $widget->id,
            'w'            => 2,
        ]);
    }

    /** @test */
    public function test_attach_without_size_uses_chart_friendly_defaults(): void
    {
        // Bug #1: a 1×1 pivot default (~80×50px in grid-layout-plus) is too
        // small for Chart.js to initialise its canvas, so a chart attached
        // without explicit w/h never rendered on personal dashboards. The
        // default must match the system seeder (12-col grid, 6×6).
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $widget->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('widgets.0.pivot.w', 6);
        $response->assertJsonPath('widgets.0.pivot.h', 6);

        $pivot = \Illuminate\Support\Facades\DB::table('dashboard_widget')
            ->where('dashboard_id', $dashboard->id)
            ->where('widget_id', $widget->id)
            ->first();

        $this->assertNotNull($pivot);
        $this->assertGreaterThanOrEqual(4, (int) $pivot->w, 'attach default width must be chart-friendly, not 1');
        $this->assertGreaterThanOrEqual(3, (int) $pivot->h, 'attach default height must be chart-friendly, not 1');
        // x/y stay at the 0/0 origin; grid-layout-plus reflows them.
        $this->assertSame(0, (int) $pivot->x);
        $this->assertSame(0, (int) $pivot->y);
    }

    /** @test */
    public function test_attach_respects_explicit_size(): void
    {
        // Explicit w/h from the client must win over the chart-friendly default.
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $widget->id,
            'x' => 0, 'y' => 0, 'w' => 3, 'h' => 2,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('widgets.0.pivot.w', 3);
        $response->assertJsonPath('widgets.0.pivot.h', 2);
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $dashboard->id,
            'widget_id'    => $widget->id,
            'w'            => 3,
            'h'            => 2,
        ]);
    }

    /** @test */
    public function test_attach_duplicate_widget_returns_409(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1,
        ]);
        $response->assertStatus(409);
    }

    /** @test */
    public function test_attach_unreadable_widget_is_forbidden(): void
    {
        // The widget read-ACL is the shared AssertsConfigEntityReadAccess trait
        // (same as reports): non-superadmin cannot reach a widget belonging to a
        // company other than the active one. A cross-company widget is therefore
        // genuinely unreadable and the attach must 403 — proving attachWidget
        // gates on widget read-access, not just dashboard write-access.
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $analyst = $this->makeUser($companyA, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $companyA->id, 'user_id' => $analyst->id]);
        $foreignWidget = Widget::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $foreignWidget->id, 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1,
        ]);
        $response->assertStatus(403);
        $this->assertDatabaseMissing('dashboard_widget', ['dashboard_id' => $dashboard->id, 'widget_id' => $foreignWidget->id]);
    }

    /** @test */
    public function test_viewer_attach_widget_to_readable_dashboard_is_forbidden(): void
    {
        // A viewer can read a published widget + a system dashboard, but must
        // never WRITE — attach is gated by dashboard write-ACL (canWrite),
        // which a viewer never satisfies.
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $widget = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1,
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function test_attach_rejects_system_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $dashboard = Dashboard::factory()->system()->create(['company_id' => $company->id]);
        $widget = Widget::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->postJson("/api/dashboards/{$dashboard->id}/widgets", [
            'widget_id' => $widget->id, 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1,
        ]);
        $response->assertStatus(403);
    }

    /** @test */
    public function test_owner_can_detach_widget_without_deleting_it(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($analyst)->deleteJson("/api/dashboards/{$dashboard->id}/widgets/{$widget->id}");
        $response->assertOk();
        $this->assertDatabaseMissing('dashboard_widget', ['dashboard_id' => $dashboard->id, 'widget_id' => $widget->id]);
        $this->assertNotNull(Widget::find($widget->id), 'detach must not delete the widget entity');
    }

    /** @test */
    public function test_detach_widget_not_on_dashboard_returns_404(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->deleteJson("/api/dashboards/{$dashboard->id}/widgets/{$widget->id}");
        $response->assertStatus(404);
    }

    // -------------------------------------------------------------------------
    // layout batch
    // -------------------------------------------------------------------------

    /** @test */
    public function test_owner_can_batch_update_layout(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $w1 = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $w2 = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $dashboard->widgets()->attach($w1->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);
        $dashboard->widgets()->attach($w2->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($analyst)->putJson("/api/dashboards/{$dashboard->id}/layout", [
            'widgets' => [
                ['widget_id' => $w1->id, 'x' => 5, 'y' => 6, 'w' => 7, 'h' => 8, 'sort' => 1, 'visible' => false],
                ['widget_id' => $w2->id, 'x' => 1, 'y' => 1, 'w' => 2, 'h' => 2, 'sort' => 2, 'visible' => true],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $dashboard->id, 'widget_id' => $w1->id, 'x' => 5, 'w' => 7, 'visible' => false,
        ]);
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $dashboard->id, 'widget_id' => $w2->id, 'x' => 1, 'sort' => 2,
        ]);
    }

    /** @test */
    public function test_layout_update_ignores_widgets_not_on_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $attached = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $stray    = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $dashboard->widgets()->attach($attached->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($analyst)->putJson("/api/dashboards/{$dashboard->id}/layout", [
            'widgets' => [
                ['widget_id' => $stray->id, 'x' => 9, 'y' => 9, 'w' => 9, 'h' => 9],
            ],
        ]);

        $response->assertOk();
        // Stray widget must not be attached as a side-effect.
        $this->assertDatabaseMissing('dashboard_widget', ['dashboard_id' => $dashboard->id, 'widget_id' => $stray->id]);
    }

    /** @test */
    public function test_layout_rejects_system_dashboard(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $dashboard = Dashboard::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->putJson("/api/dashboards/{$dashboard->id}/layout", [
            'widgets' => [['widget_id' => 1, 'x' => 0]],
        ]);
        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // clone (O3)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_clone_system_dashboard_creates_personal_copy_with_pivot_links(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $system = Dashboard::factory()->system()->create([
            'company_id' => $company->id,
            'name'       => ['ru' => 'Системный', 'en' => 'System'],
        ]);
        $w1 = Widget::factory()->system()->create(['company_id' => $company->id]);
        $w2 = Widget::factory()->system()->create(['company_id' => $company->id]);
        $system->widgets()->attach($w1->id, ['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4, 'sort' => 1, 'visible' => true]);
        $system->widgets()->attach($w2->id, ['x' => 5, 'y' => 6, 'w' => 7, 'h' => 8, 'sort' => 2, 'visible' => false]);

        $response = $this->actingAs($analyst)->postJson("/api/dashboards/{$system->id}/clone");
        $response->assertStatus(201);

        $cloneId = $response->json('id');
        $this->assertNotSame($system->id, $cloneId);
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('is_published', false);
        $response->assertJsonPath('user_id', $analyst->id);
        // Name carries the (copy) suffix.
        $response->assertJsonPath('name.en', 'System (copy)');

        // Original system dashboard is untouched.
        $this->assertTrue(Dashboard::find($system->id)->is_system);

        // Clone holds copies of both pivot placements (widgets by reference).
        $clone = Dashboard::find($cloneId);
        $this->assertSame(2, $clone->widgets()->count());
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $cloneId, 'widget_id' => $w1->id, 'x' => 1, 'w' => 3, 'sort' => 1,
        ]);
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $cloneId, 'widget_id' => $w2->id, 'x' => 5, 'h' => 8, 'visible' => false,
        ]);
        // The widget entities themselves are NOT duplicated.
        $this->assertSame(2, Widget::count());
    }

    /** @test */
    public function test_clone_forbidden_when_dashboard_not_readable(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        // Unpublished personal dashboard a viewer cannot read.
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->postJson("/api/dashboards/{$dashboard->id}/clone");
        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // /data endpoint (implemented by macrodata-engineer)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_data_endpoint_returns_dashboard_shape(): void
    {
        $company   = $this->makeCompany('A');
        $admin     = $this->makeUser($company, 'admin');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson("/api/dashboards/{$dashboard->id}/data");
        $response->assertOk();

        // The endpoint now calls WidgetDataService in batch. No visible widgets
        // on this dashboard → widgets map is empty, but meta is always present.
        $response->assertJsonStructure(['widgets', 'meta']);
        $this->assertSame([], $response->json('widgets'));
        $this->assertArrayHasKey('period_from', $response->json('meta'));
        $this->assertArrayHasKey('period_to', $response->json('meta'));
    }

    /** @test */
    public function test_data_endpoint_enforces_read_acl(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson("/api/dashboards/{$dashboard->id}/data");
        $response->assertStatus(403);
    }
}
