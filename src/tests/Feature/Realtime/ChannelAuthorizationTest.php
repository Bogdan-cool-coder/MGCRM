<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Channel authorization (Phase 7a): the routes/channels.php callbacks must grant
 * exactly the subscriptions the REST layer would allow — owner/dept-peer in,
 * outsider out — by reusing the SAME Policy + VisibilityResolver surface.
 *
 * The broadcaster's auth() short-circuits for the null/log driver used in tests,
 * so we drive the real callback map directly through the protected
 * verifyUserCanAccessChannel(): it runs the actual closure (Gate/Policy +
 * department-subtree) and throws AccessDeniedHttpException on deny — exactly what
 * a real /broadcasting/auth request would surface as a 403.
 */
class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the registered channel callback for $channel as $user. Returns true
     * when the subscription is granted, false when denied (403).
     */
    private function authorizes(User $user, string $channel): bool
    {
        // Force the user this request "authenticates" as, bypassing the guard —
        // we are unit-testing the authorization closure, not the guard.
        Broadcast::resolveAuthenticatedUserUsing(fn () => $user);

        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::connection();

        $method = new \ReflectionMethod(Broadcaster::class, 'verifyUserCanAccessChannel');
        $method->setAccessible(true);

        $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => $channel]);
        $request->setUserResolver(fn () => $user);

        try {
            $method->invoke($broadcaster, $request, $channel);

            return true;
        } catch (AccessDeniedHttpException) {
            return false;
        }
    }

    // ---- Personal channel ---------------------------------------------------

    public function test_user_channel_grants_self_denies_others(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->assertTrue($this->authorizes($me, 'user.'.$me->id));
        $this->assertFalse($this->authorizes($me, 'user.'.$other->id));
    }

    // ---- Deal entity channel (DealPolicy::view) -----------------------------

    public function test_deal_channel_grants_owner_denies_outsider(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $outsider = User::factory()->create(['role' => Role::Manager]);
        $deal = Deal::factory()->forOwner($owner)->create();

        $this->assertTrue($this->authorizes($owner, 'deal.'.$deal->id));
        $this->assertFalse($this->authorizes($outsider, 'deal.'.$deal->id));
    }

    public function test_deal_channel_grants_department_peer(): void
    {
        $dept = Department::create(['name' => 'Sales']);
        $head = User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id]);
        $peer = User::factory()->create(['role' => Role::Manager, 'department_id' => $dept->id]);
        $deal = Deal::factory()->create([
            'owner_user_id' => $peer->id,
            'department_id' => $dept->id,
        ]);

        // A department peer (not the owner) may VIEW → may subscribe.
        $this->assertTrue($this->authorizes($head, 'deal.'.$deal->id));
    }

    public function test_deal_channel_denies_missing_deal(): void
    {
        $user = User::factory()->create(['role' => Role::Admin]);

        $this->assertFalse($this->authorizes($user, 'deal.999999'));
    }

    // ---- Company / Contact entity channels ----------------------------------

    public function test_company_channel_uses_view_policy(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $outsider = User::factory()->create(['role' => Role::Manager]);
        $company = Company::factory()->create(['owner_user_id' => $owner->id]);

        $this->assertTrue($this->authorizes($owner, 'company.'.$company->id));
        $this->assertFalse($this->authorizes($outsider, 'company.'.$company->id));
    }

    public function test_contact_channel_uses_view_policy(): void
    {
        $owner = User::factory()->create(['role' => Role::Manager]);
        $outsider = User::factory()->create(['role' => Role::Manager]);
        $contact = Contact::factory()->create(['owner_id' => $owner->id]);

        $this->assertTrue($this->authorizes($owner, 'contact.'.$contact->id));
        $this->assertFalse($this->authorizes($outsider, 'contact.'.$contact->id));
    }

    // ---- Department list channels -------------------------------------------

    public function test_dept_list_channels_grant_own_subtree_deny_foreign(): void
    {
        $parent = Department::create(['name' => 'Sales']);
        $child = Department::create(['name' => 'Sales North', 'parent_id' => $parent->id]);
        $foreign = Department::create(['name' => 'Legal']);

        $head = User::factory()->create(['role' => Role::Manager, 'department_id' => $parent->id]);

        // Own department + descendant → granted for all three list flavours.
        foreach (['deals', 'tasks', 'contacts'] as $list) {
            $this->assertTrue($this->authorizes($head, 'dept.'.$parent->id.'.'.$list));
            $this->assertTrue($this->authorizes($head, 'dept.'.$child->id.'.'.$list));
            $this->assertFalse($this->authorizes($head, 'dept.'.$foreign->id.'.'.$list));
        }
    }

    public function test_dept_list_channel_grants_all_scope_role_any_department(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]); // All scope
        $foreign = Department::create(['name' => 'Legal']);

        // An All-scope role may observe any department's list stream.
        $this->assertTrue($this->authorizes($admin, 'dept.'.$foreign->id.'.deals'));
        $this->assertTrue($this->authorizes($admin, 'dept.'.$foreign->id.'.tasks'));
        $this->assertTrue($this->authorizes($admin, 'dept.'.$foreign->id.'.contacts'));
    }

    public function test_dept_list_channel_denies_departmentless_manager_foreign(): void
    {
        // A manager with no department resolves to an empty subtree ([-1]) → no
        // foreign department list is reachable.
        $nomad = User::factory()->create(['role' => Role::Manager, 'department_id' => null]);
        $someDept = Department::create(['name' => 'Sales']);

        $this->assertFalse($this->authorizes($nomad, 'dept.'.$someDept->id.'.deals'));
    }
}
