<?php

use App\Models\AuditEntry;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\User;
use App\Registry\PanelEvaluator;
use App\Registry\PatientContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->clinician = User::factory()->create();
    $this->patient = Patient::factory()->create(['symptom_onset_on' => now()->subYears(4)->subMonths(2)]);
    $this->actingAs($this->clinician);
});

function recordDiagnosis(array $overrides = []): array
{
    return test()->postJson('/api/patients/{test()->patient->registry_no}/diagnoses', array_merge([
        'code' => 'pd_probable',
        'certainty' => 'probable',
        'diagnosed_on' => today()->toDateString(),
    ], $overrides))->json();
}

it('opens exactly one module, marked primary, for a first diagnosis', function () {
    // Criterion 1.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable',
        'certainty' => 'probable',
        'diagnosed_on' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('module_opened', 'pd');

    $modules = $this->patient->modules()->open()->get();

    expect($modules)->toHaveCount(1)
        ->and($modules->first()->module)->toBe('pd')
        ->and($modules->first()->is_primary)->toBeTrue();
});

it('supersedes rather than overwrites when a diagnosis is revised', function () {
    // Criteria 2 and 4.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->subYears(2)->toDateString(),
    ])->assertCreated();

    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'msa_p',
        'certainty' => 'probable',
        'diagnosed_on' => today()->toDateString(),
        'rationale' => 'Early autonomic failure and poor levodopa response; revised from PD.',
    ])->assertCreated()->assertJsonPath('module_opened', 'atypical');

    $all = Diagnosis::orderBy('id')->get();

    expect($all)->toHaveCount(2)
        ->and($all[0]->code)->toBe('pd_probable')
        ->and($all[0]->superseded_at)->not->toBeNull()
        ->and($all[0]->superseded_by_id)->toBe($all[1]->id)
        ->and($all[1]->isCurrent())->toBeTrue();

    // The PD module stays open: the data collected under it is still real.
    $modules = $this->patient->modules()->open()->pluck('module')->all();

    expect($modules)->toContain('pd')->toContain('atypical');

    // Criterion 5: exactly one primary, and it is the newest.
    $primary = $this->patient->modules()->open()->where('is_primary', true)->get();

    expect($primary)->toHaveCount(1)
        ->and($primary->first()->module)->toBe('atypical');
});

it('refuses a revision with no rationale', function () {
    // Criterion 3.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated();

    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'msa_p', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrorFor('rationale');

    expect(Diagnosis::count())->toBe(1);
});

it('keeps the whole history queryable with rationales', function () {
    foreach ([
        ['pd_probable', 'Initial assessment'],
        ['msa_p', 'Early autonomic failure, revised from PD'],
        ['psp_rs', 'Vertical gaze palsy and early falls, revised again'],
    ] as $index => [$code, $rationale]) {
        $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
            'code' => $code,
            'certainty' => 'probable',
            'diagnosed_on' => today()->subMonths(10 - $index)->toDateString(),
            'rationale' => $rationale,
        ])->assertCreated();
    }

    $body = $this->getJson("/api/patients/{$this->patient->registry_no}/diagnoses")->assertOk()->json();

    expect($body['diagnoses'])->toHaveCount(3)
        ->and($body['revision_count'])->toBe(2)
        ->and(collect($body['diagnoses'])->firstWhere('code', 'msa_p')['rationale'])
        ->toBe('Early autonomic failure, revised from PD');
});

it('derives disease duration and never takes it from the client', function () {
    // Criterion 6.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
        'disease_duration_years' => 99,
    ])->assertCreated();

    $body = $this->getJson("/api/patients/{$this->patient->registry_no}")->assertOk()->json();

    expect($body['diagnosis']['disease_duration_years'])->toBeGreaterThan(4.0)
        ->and($body['diagnosis']['disease_duration_years'])->toBeLessThan(4.5);
});

it('returns a null duration when onset is unknown', function () {
    // Criterion 7.
    $patient = Patient::factory()->create(['symptom_onset_on' => null]);

    $this->postJson("/api/patients/{$patient->registry_no}/diagnoses", [
        'code' => 'et', 'certainty' => 'established', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated();

    $this->getJson("/api/patients/{$patient->registry_no}")
        ->assertOk()
        ->assertJsonPath('diagnosis.disease_duration_years', null);
});

it('records symptom onset, including an estimated one', function () {
    $this->patchJson("/api/patients/{$this->patient->registry_no}", [
        'symptom_onset_on' => null,
        'symptom_onset_estimated' => true,
        'first_symptom' => 'tremor',
        'side_of_onset' => 'right',
    ])->assertOk()->assertJsonPath('disease_duration_years', null);

    expect($this->patient->fresh()->symptom_onset_estimated)->toBeTrue();
});

it('opens panels from the diagnosis and does so only once', function () {
    // Criterion 9.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('panels_opened', ['synuclein_nonmotor']);

    $opened = app(PanelEvaluator::class)->evaluate($this->patient->fresh());

    expect($opened)->toBe([])
        ->and($this->patient->panels()->open()->count())->toBe(1);

    $panel = $this->patient->panels()->open()->sole();

    expect($panel->trigger)->toBe('diagnosis_in')
        ->and($panel->trigger_context['diagnosis_code'])->toBe('pd_probable');
});

it('leaves panels dormant when their inputs do not exist yet', function () {
    // The dopaminergic panel opens on drug exposure, which arrives with the
    // medication slice. Until then it must not fire on a diagnosis label.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated();

    expect($this->patient->panels()->open()->pluck('panel')->all())
        ->not->toContain('dopaminergic_therapy');
});

it('implements every rule the config refers to', function () {
    $configured = collect(config('modules.panels'))
        ->flatMap(fn (array $panel) => array_keys($panel['opens_when'] ?? []))
        ->unique()
        ->values()
        ->all();

    expect(array_diff($configured, PanelEvaluator::supportedRules()))->toBe([]);
});

it('closes a module with a reason and leaves the row in place', function () {
    // Criterion 10.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated();

    $this->postJson("/api/patients/{$this->patient->registry_no}/modules/pd/close", [
        'reason' => 'Diagnosis revised; PD data collection complete',
    ])->assertOk();

    $module = $this->patient->modules()->sole();

    expect($module->closed_at)->not->toBeNull()
        ->and($module->closed_reason)->toBe('Diagnosis revised; PD data collection complete')
        ->and($module->is_primary)->toBeFalse();
});

it('lets a clinician choose which module the banner stages from', function () {
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->subYear()->toDateString(),
    ])->assertCreated();

    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'msa_p', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
        'rationale' => 'Revised on autonomic features',
    ])->assertCreated();

    $this->patchJson("/api/patients/{$this->patient->registry_no}/modules/pd/primary")->assertOk();

    $primary = $this->patient->modules()->open()->where('is_primary', true)->sole();

    expect($primary->module)->toBe('pd');
});

it('serves the vocabulary from config', function () {
    $body = $this->getJson('/api/diagnoses/vocabulary')->assertOk()->json();

    $codes = collect($body['groups'])->flatMap(fn ($group) => collect($group['diagnoses'])->pluck('code'))->all();

    expect($codes)->toEqualCanonicalizing(array_keys(config('modules.diagnoses')))
        ->and($body['certainties'])->toBe(Diagnosis::CERTAINTIES);
});

it('exposes exactly the agreed expression context keys', function () {
    // Criterion 8: compare key sets, so a stray addition fails here.
    $context = PatientContext::for($this->patient);

    expect(array_keys($context))->toEqualCanonicalizing(config('modules.expression_context'));
});

it('writes one audit entry for each diagnosis, with the code and certainty', function () {
    // Criterion 12.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated();

    $entry = AuditEntry::where('action', 'diagnosis.recorded')->sole();

    expect($entry->context['code'])->toBe('pd_probable')
        ->and($entry->context['certainty'])->toBe('probable')
        ->and($entry->actor_id)->toBe($this->clinician->id);
});

it('keeps diagnosis recording to clinicians', function () {
    // Criterion 11.
    $this->actingAs(User::factory()->researchAssistant()->create())
        ->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
            'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
        ])->assertForbidden();

    // ...but they may still read the history.
    $this->getJson("/api/patients/{$this->patient->registry_no}/diagnoses")->assertOk();
});

it('offers the core assessments to an undetermined patient and withholds only module instruments', function () {
    // Criterion 15. A first visit often ends without a firm diagnosis, and a
    // patient carrying `undetermined` must still be able to complete the core
    // set — otherwise the visit collects nothing and the registry holds an
    // entry with no data in it.
    $core = array_keys(config('modules.core_assessments'));

    $before = $this->getJson("/api/patients/{$this->patient->registry_no}")
        ->assertOk()->json('assessments');

    expect(array_column($before['core'], 'code'))->toEqualCanonicalizing($core)
        ->and($before['core'])->each->toHaveKey('available', true)
        ->and($before['module'])->toBe([]);

    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'undetermined', 'certainty' => 'suspected', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated()->assertJsonPath('module_opened', 'other');

    $after = $this->getJson("/api/patients/{$this->patient->registry_no}")
        ->assertOk()->json('assessments');

    // `other` carries no staging instrument, so nothing module-specific is
    // offered — but nothing cross-disease is withheld either.
    expect(array_column($after['core'], 'code'))->toEqualCanonicalizing($core)
        ->and($after['module'])->toBe([]);
});

it('adds the module instrument once the diagnosis names a disease', function () {
    // The other half of criterion 15: module-specific instruments, and only
    // those, wait for the label.
    $this->postJson("/api/patients/{$this->patient->registry_no}/diagnoses", [
        'code' => 'pd_probable', 'certainty' => 'probable', 'diagnosed_on' => today()->toDateString(),
    ])->assertCreated();

    $plan = $this->getJson("/api/patients/{$this->patient->registry_no}")
        ->assertOk()->json('assessments');

    expect($plan['module'])->toHaveCount(1)
        ->and($plan['module'][0]['module'])->toBe('pd')
        ->and($plan['module'][0]['instrument'])->toBe('HY')
        ->and($plan['core'])->toHaveCount(count(config('modules.core_assessments')));
});
