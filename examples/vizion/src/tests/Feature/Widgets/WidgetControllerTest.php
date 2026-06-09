<?php

declare(strict_types=1);

namespace Tests\Feature\Widgets;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Dashboard;
use App\Models\User;
use App\Models\Widget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for WidgetController:
 *   - index visibility matrix (system / published / personal × roles)
 *   - show read-ACL
 *   - create / update / delete (owner, admin, viewer-403)
 *   - DELETE widget used in a dashboard => 409 + count (N3)
 *   - publish / unpublish (admin ok, analyst 403, system reject)
 *   - cross-company isolation
 *   - /data stub returns the empty chart shape
 */
class WidgetControllerTest extends TestCase
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
    public function test_index_admin_sees_system_and_company_widgets(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $system    = Widget::factory()->system()->create(['company_id' => $company->id]);
        $personal  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $published = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson('/api/widgets');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($personal->id, $ids);
        $this->assertContains($published->id, $ids);
    }

    /** @test */
    public function test_index_analyst_sees_system_published_and_own_but_not_others_private(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $other   = $this->makeUser($company, 'analyst');

        $system        = Widget::factory()->system()->create(['company_id' => $company->id]);
        $own           = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $othersPrivate = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);
        $othersPub     = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $other->id]);

        $response = $this->actingAs($analyst)->getJson('/api/widgets');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($own->id, $ids);
        $this->assertContains($othersPub->id, $ids);
        $this->assertNotContains($othersPrivate->id, $ids, 'analyst must not see another user private widget');
    }

    /** @test */
    public function test_index_viewer_sees_only_system_and_published(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');

        $system   = Widget::factory()->system()->create(['company_id' => $company->id]);
        $personal = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $pub      = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson('/api/widgets');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($pub->id, $ids);
        $this->assertNotContains($personal->id, $ids, 'viewer must not see unpublished personal widget');
    }

    /** @test */
    public function test_index_includes_used_in_dashboards_count(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 2, 'h' => 2]);

        $response = $this->actingAs($admin)->getJson('/api/widgets');
        $response->assertOk();

        $entry = collect($response->json())->firstWhere('id', $widget->id);
        $this->assertSame(1, $entry['used_in_dashboards_count']);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    /** @test */
    public function test_show_returns_widget_with_config_and_author(): void
    {
        $company = $this->makeCompany('A');
        $admin = User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => 'admin',
            'name'              => 'Wendy Widget',
            'email'             => 'wendy@example.com',
            'company_accesses'  => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson("/api/widgets/{$widget->id}");
        $response->assertOk();
        $response->assertJsonPath('id', $widget->id);
        $response->assertJsonPath('author.id', $admin->id);
        $response->assertJsonPath('author.name', 'Wendy Widget');
        $this->assertArrayHasKey('config', $response->json());
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('used_in_dashboards_count', 0);
    }

    /** @test */
    public function test_viewer_cannot_show_unpublished_personal_widget(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson("/api/widgets/{$widget->id}");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_show_cross_company_widget_is_forbidden_for_admin(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA   = $this->makeUser($companyA, 'admin');
        $widgetB  = Widget::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $response = $this->actingAs($adminA)->getJson("/api/widgets/{$widgetB->id}");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_superadmin_can_show_cross_company_widget(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $superadmin = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);
        $widgetOther = Widget::factory()->create(['company_id' => $other->id, 'user_id' => null]);

        $response = $this->actingAs($superadmin)->getJson("/api/widgets/{$widgetOther->id}");
        $response->assertOk();
    }

    // -------------------------------------------------------------------------
    // create / update / delete
    // -------------------------------------------------------------------------

    /** @test */
    public function test_analyst_can_create_widget(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($analyst)->postJson('/api/widgets', [
            'name'   => ['ru' => 'Новый', 'en' => 'New'],
            'config' => ['primary_model' => 'Deal', 'chart' => ['type' => 'pie']],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('user_id', $analyst->id);
        $this->assertDatabaseHas('widgets', ['id' => $response->json('id'), 'company_id' => $company->id]);
    }

    /** @test */
    public function test_viewer_cannot_create_widget(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->postJson('/api/widgets', [
            'name'   => ['en' => 'X'],
            'config' => ['primary_model' => 'Deal'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_owner_analyst_can_update_own_widget(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->putJson("/api/widgets/{$widget->id}", [
            'name' => ['ru' => 'Изм', 'en' => 'Changed'],
        ]);

        $response->assertOk();
        $this->assertSame('Changed', $widget->fresh()->getTranslation('name', 'en'));
    }

    /** @test */
    public function test_analyst_cannot_publish_via_update(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        // is_published rule is only added for admin/superadmin, so it is
        // silently dropped for analyst — widget stays unpublished.
        $response = $this->actingAs($analyst)->putJson("/api/widgets/{$widget->id}", [
            'is_published' => true,
        ]);

        $response->assertOk();
        $this->assertFalse($widget->fresh()->is_published);
    }

    /** @test */
    public function test_analyst_cannot_update_other_users_widget(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $other   = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);

        $response = $this->actingAs($analyst)->putJson("/api/widgets/{$widget->id}", [
            'name' => ['en' => 'Hijack'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_update_rejects_system_widget(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $widget  = Widget::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->putJson("/api/widgets/{$widget->id}", [
            'name' => ['en' => 'Nope'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_owner_can_delete_widget(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->deleteJson("/api/widgets/{$widget->id}");
        $response->assertOk();
        $this->assertNull(Widget::find($widget->id));
    }

    /** @test */
    public function test_delete_cascades_pinned_widget_generation_chat(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $chat = Chat::create([
            'user_id'    => $analyst->id,
            'company_id' => $company->id,
            'type'       => 'widget_generation',
            'widget_id'  => $widget->id,
        ]);

        $response = $this->actingAs($analyst)->deleteJson("/api/widgets/{$widget->id}");
        $response->assertOk();
        $this->assertNull(Widget::find($widget->id));
        $this->assertNull(Chat::find($chat->id), 'pinned widget_generation chat must cascade');
    }

    /** @test */
    public function test_viewer_cannot_delete_widget(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->deleteJson("/api/widgets/{$widget->id}");
        $response->assertStatus(403);
        $this->assertNotNull(Widget::find($widget->id));
    }

    /** @test */
    public function test_delete_rejects_system_widget(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $widget  = Widget::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->deleteJson("/api/widgets/{$widget->id}");
        $response->assertStatus(403);
        $this->assertNotNull(Widget::find($widget->id));
    }

    /** @test */
    public function test_delete_widget_used_in_dashboards_returns_409_with_count(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $dashboard1 = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard2 = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard1->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);
        $dashboard2->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($admin)->deleteJson("/api/widgets/{$widget->id}");
        $response->assertStatus(409);
        $response->assertJsonPath('used_in_dashboards_count', 2);
        // Widget must still exist (delete blocked).
        $this->assertNotNull(Widget::find($widget->id));
    }

    /** @test */
    public function test_force_delete_widget_used_in_dashboards_detaches_and_deletes(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $widget = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        // A second widget that must survive the force-delete untouched.
        $survivor = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $dashboard1 = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard2 = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $dashboard1->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);
        $dashboard2->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);
        // survivor sits on dashboard2 alongside the deleted widget.
        $dashboard2->widgets()->attach($survivor->id, ['x' => 1, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($admin)->deleteJson("/api/widgets/{$widget->id}?force=true");
        $response->assertOk();

        // Widget gone, all of its pivot rows gone.
        $this->assertNull(Widget::find($widget->id));
        $this->assertDatabaseMissing('dashboard_widget', ['widget_id' => $widget->id]);

        // Dashboards themselves untouched.
        $this->assertNotNull(Dashboard::find($dashboard1->id));
        $this->assertNotNull(Dashboard::find($dashboard2->id));

        // The other widget and its placement survive.
        $this->assertNotNull(Widget::find($survivor->id));
        $this->assertDatabaseHas('dashboard_widget', [
            'dashboard_id' => $dashboard2->id,
            'widget_id'    => $survivor->id,
        ]);
    }

    /** @test */
    public function test_force_delete_does_not_bypass_acl_for_non_owner_analyst(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $other   = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);

        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($analyst)->deleteJson("/api/widgets/{$widget->id}?force=true");
        $response->assertStatus(403);
        $this->assertNotNull(Widget::find($widget->id), 'force must not delete another analyst widget');
        $this->assertDatabaseHas('dashboard_widget', ['widget_id' => $widget->id]);
    }

    /** @test */
    public function test_force_delete_does_not_bypass_acl_for_system_widget(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $widget  = Widget::factory()->system()->create(['company_id' => $company->id]);

        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $superadmin->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($superadmin)->deleteJson("/api/widgets/{$widget->id}?force=true");
        $response->assertStatus(403);
        $this->assertNotNull(Widget::find($widget->id), 'force must not delete a system widget');
    }

    /** @test */
    public function test_force_delete_does_not_bypass_acl_for_viewer(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $dashboard = Dashboard::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $dashboard->widgets()->attach($widget->id, ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]);

        $response = $this->actingAs($viewer)->deleteJson("/api/widgets/{$widget->id}?force=true");
        $response->assertStatus(403);
        $this->assertNotNull(Widget::find($widget->id), 'force must not let a viewer delete');
    }

    // -------------------------------------------------------------------------
    // publish / unpublish
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_publish_widget(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->postJson("/api/widgets/{$widget->id}/publish");
        $response->assertOk();
        $response->assertJsonPath('is_published', true);
        $this->assertTrue($widget->fresh()->is_published);
    }

    /** @test */
    public function test_admin_can_unpublish_widget(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $widget  = Widget::factory()->published()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->postJson("/api/widgets/{$widget->id}/unpublish");
        $response->assertOk();
        $response->assertJsonPath('is_published', false);
        $this->assertFalse($widget->fresh()->is_published);
    }

    /** @test */
    public function test_analyst_cannot_publish_widget(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->postJson("/api/widgets/{$widget->id}/publish");
        $response->assertStatus(403);
        $this->assertFalse($widget->fresh()->is_published);
    }

    /** @test */
    public function test_publish_rejects_system_widget(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $widget  = Widget::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($superadmin)->postJson("/api/widgets/{$widget->id}/publish");
        $response->assertStatus(403);
    }

    /** @test */
    public function test_admin_cannot_publish_widget_from_another_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA   = $this->makeUser($companyA, 'admin');
        $widgetB  = Widget::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $response = $this->actingAs($adminA)->postJson("/api/widgets/{$widgetB->id}/publish");
        $response->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // /data endpoint (implemented by macrodata-engineer)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_data_endpoint_returns_chart_shape(): void
    {
        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson("/api/widgets/{$widget->id}/data");
        $response->assertOk();

        // The endpoint now calls WidgetDataService — it always returns the N1 shape.
        // A widget created by the factory has no valid primary_model in config,
        // so WidgetDataService gracefully degrades to an empty payload with meta.
        $response->assertJsonStructure(['labels', 'datasets', 'meta']);
        $this->assertArrayHasKey('period_from', $response->json('meta'));
        $this->assertArrayHasKey('period_to', $response->json('meta'));
        $this->assertArrayHasKey('period_applied', $response->json('meta'));
    }

    /** @test */
    public function test_data_endpoint_enforces_read_acl(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');
        $author  = $this->makeUser($company, 'analyst');
        $widget  = Widget::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson("/api/widgets/{$widget->id}/data");
        $response->assertStatus(403);
    }
}
