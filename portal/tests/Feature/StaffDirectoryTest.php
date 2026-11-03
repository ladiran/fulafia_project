<?php

namespace Tests\Feature;

use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $name, string $cadre = null, string $category = 'academic', ?User $user = null, array $extra = []): StaffMember
    {
        return StaffMember::create($extra + [
            'name' => $name, 'category' => $category, 'cadre' => $cadre, 'user_id' => $user?->id, 'is_published' => true,
        ]);
    }

    private function hodUser(): User
    {
        return User::factory()->create(['role' => User::ROLE_PRINCIPAL_ADMIN]);
    }

    private function names($groups): array
    {
        return $groups->map(fn ($g) => $g->pluck('name')->all())->all();
    }

    public function test_academic_staff_are_ordered_by_cadre(): void
    {
        $this->member('Ada GA', 'Graduate Assistant');
        $this->member('Bola LI', 'Lecturer I');
        $this->member('Chi Prof', 'Professor');
        $this->member('Dayo Emeritus', 'Emeritus Professor');
        $this->member('Eke SL', 'Senior Lecturer');
        $this->member('Femi AL', 'Assistant Lecturer');
        $this->member('Gozie AP', 'Associate Professor');
        $this->member('Hauwa LII', 'Lecturer II');
        $this->member('Ike DP', 'Distinguished Professor');

        $order = StaffMember::directory(StaffMember::with('user')->get())['academic']->pluck('name')->all();

        $this->assertSame(
            ['Dayo Emeritus', 'Ike DP', 'Chi Prof', 'Gozie AP', 'Eke SL', 'Bola LI', 'Hauwa LII', 'Femi AL', 'Ada GA'],
            $order
        );
    }

    public function test_the_hod_always_follows_the_distinguished_professor_whatever_their_own_cadre(): void
    {
        foreach (['Graduate Assistant', 'Lecturer I', 'Professor', 'Distinguished Professor', 'Emeritus Professor'] as $cadre) {
            StaffMember::query()->delete();
            User::query()->delete();
            $hod = $this->member('The HoD', $cadre, user: $this->hodUser());
            $this->member('Emeritus One', 'Emeritus Professor');
            $this->member('Distinguished One', 'Distinguished Professor');
            $this->member('Prof One', 'Professor');
            $this->member('Lecturer One', 'Lecturer I');

            $order = StaffMember::directory(StaffMember::with('user')->get())['academic']->pluck('name')->all();

            $this->assertSame(['Emeritus One', 'Distinguished One', 'The HoD', 'Prof One', 'Lecturer One'], $order, "HoD with cadre {$cadre}");
        }
    }

    public function test_the_hod_comes_after_the_emeritus_professors_even_when_there_is_no_distinguished_professor(): void
    {
        $this->member('Emeritus One', 'Emeritus Professor');
        $this->member('The HoD', 'Lecturer II', user: $this->hodUser());
        $this->member('Prof One', 'Professor');

        $order = StaffMember::directory(StaffMember::with('user')->get())['academic']->pluck('name')->all();

        $this->assertSame(['Emeritus One', 'The HoD', 'Prof One'], $order);
    }

    public function test_the_position_follows_the_new_hod_after_a_handover(): void
    {
        $oldHod = $this->hodUser();
        $newHod = User::factory()->create(['role' => User::ROLE_LECTURER]);
        $this->member('Old HoD', 'Professor', user: $oldHod);
        $this->member('New HoD', 'Lecturer I', user: $newHod);
        $this->member('Distinguished One', 'Distinguished Professor');
        $this->member('Prof One', 'Professor');

        $before = StaffMember::directory(StaffMember::with('user')->get())['academic']->pluck('name')->all();
        $this->assertSame(['Distinguished One', 'Old HoD', 'Prof One', 'New HoD'], $before);

        $oldHod->update(['role' => User::ROLE_LECTURER]);
        $newHod->update(['role' => User::ROLE_PRINCIPAL_ADMIN]);

        $after = StaffMember::directory(StaffMember::with('user')->get())['academic']->pluck('name')->all();
        $this->assertSame(['Distinguished One', 'New HoD', 'Old HoD', 'Prof One'], $after);
    }

    public function test_ties_use_the_manual_order_then_the_name(): void
    {
        $this->member('Zara', 'Lecturer I', extra: ['sort_order' => 10]);
        $this->member('Ben', 'Lecturer I', extra: ['sort_order' => 50]);
        $this->member('Amos', 'Lecturer I', extra: ['sort_order' => 50]);

        $order = StaffMember::directory(StaffMember::all())['academic']->pluck('name')->all();

        $this->assertSame(['Zara', 'Amos', 'Ben'], $order);
    }

    public function test_staff_are_grouped_into_the_three_categories_in_order(): void
    {
        $this->member('Tola Technologist', null, 'technical');
        $this->member('Uche Secretary', null, 'administrative');
        $this->member('Vera Lecturer', 'Lecturer I');

        $groups = StaffMember::directory(StaffMember::all());

        $this->assertSame(['academic', 'technical', 'administrative'], $groups->keys()->all());
        $this->get('/staff-directory')->assertSeeInOrder(['Academic Staff', 'Vera Lecturer', 'Technical Staff', 'Tola Technologist', 'Administrative Staff', 'Uche Secretary']);
    }

    public function test_empty_categories_are_not_shown_and_hidden_staff_stay_hidden(): void
    {
        $this->member('Vera Lecturer', 'Lecturer I');
        $this->member('Hidden Tech', null, 'technical', extra: ['is_published' => false]);

        $this->get('/staff-directory')->assertSee('Academic Staff')->assertDontSee('Technical Staff')->assertDontSee('Hidden Tech');
    }

    public function test_the_public_page_shows_the_cadre_and_marks_the_hod(): void
    {
        $this->member('Vera Lecturer', 'Senior Lecturer');
        $this->member('The HoD', 'Professor', user: $this->hodUser());

        $this->get('/staff-directory')->assertSee('Senior Lecturer')->assertSee('Head of Department');
        $this->get('/staff-directory/'.StaffMember::where('name', 'The HoD')->value('id'))->assertSee('Head of Department · Professor');
    }

    public function test_the_admin_form_validates_category_and_cadre(): void
    {
        $eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);
        $post = fn (array $data) => $this->actingAs($eo)->post('/admin/content/staff', $data);

        $post(['name' => 'No Category'])->assertSessionHasErrors('category');
        $post(['name' => 'No Cadre', 'category' => 'academic'])->assertSessionHasErrors('cadre');
        $post(['name' => 'Bad Cadre', 'category' => 'academic', 'cadre' => 'Vice Chancellor'])->assertSessionHasErrors('cadre');
        $post(['name' => 'Bad Category', 'category' => 'visitor'])->assertSessionHasErrors('category');
        $this->assertSame(0, StaffMember::count());

        $post(['name' => 'Good Academic', 'category' => 'academic', 'cadre' => 'Lecturer II'])->assertSessionHasNoErrors();
        // A cadre is ignored for technical and administrative staff.
        $post(['name' => 'Technologist', 'category' => 'technical', 'cadre' => 'Professor', 'position' => 'Chief Technologist'])->assertSessionHasNoErrors();

        $this->assertSame('Lecturer II', StaffMember::where('name', 'Good Academic')->value('cadre'));
        $this->assertNull(StaffMember::where('name', 'Technologist')->value('cadre'));
        $this->actingAs($eo)->get('/admin/content/staff')->assertOk()->assertSee('Technical Staff');
    }

    public function test_a_new_account_can_carry_a_cadre_into_the_directory(): void
    {
        $eo = User::factory()->create(['role' => User::ROLE_SECONDARY_ADMIN]);

        $this->actingAs($eo)->post('/admin/accounts', [
            'name' => 'Ada Obi', 'email' => 'ada@example.com', 'identifier' => 'STAFF-900', 'add_to_directory' => '1', 'cadre' => 'Senior Lecturer',
        ])->assertSessionHasNoErrors();
        $this->actingAs($eo)->post('/admin/accounts', [
            'name' => 'Bad', 'email' => 'bad@example.com', 'identifier' => 'STAFF-901', 'add_to_directory' => '1', 'cadre' => 'King',
        ])->assertSessionHasErrors('cadre');

        $this->assertSame('Senior Lecturer', StaffMember::firstOrFail()->cadre);
        $this->assertSame('academic', StaffMember::firstOrFail()->category);
    }
}
