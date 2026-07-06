<?php

namespace Tests\Feature\Monitoring;

use App\Modules\Monitoring\Models\Comment;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Shared\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitoringAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_staff_can_view_monitoring_entities_and_client_viewer_cannot(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        $clientViewer = $this->makeUser(RoleName::ClientViewer);

        foreach ([MonitoredSubject::class, ContentItem::class, Story::class, Mention::class, RecognitionDetection::class, SentimentAnalysis::class, MetricSnapshot::class] as $model) {
            $this->assertTrue($analyst->can('viewAny', $model), "Analyst should view [{$model}].");
            $this->assertFalse($clientViewer->can('viewAny', $model), "Client viewer must not view [{$model}].");
        }
    }

    public function test_roster_configuration_requires_monitoring_manage(): void
    {
        $analyst = $this->makeUser(RoleName::Analyst);
        $clientViewer = $this->makeUser(RoleName::ClientViewer);
        $subject = MonitoredSubject::factory()->create();

        $this->assertTrue($analyst->can('create', MonitoredSubject::class));
        $this->assertTrue($analyst->can('update', $subject));
        $this->assertTrue($analyst->can('delete', $subject));

        $this->assertFalse($clientViewer->can('create', MonitoredSubject::class));
        $this->assertFalse($clientViewer->can('update', $subject));
        $this->assertFalse($clientViewer->can('delete', $subject));
    }

    public function test_ingested_content_is_never_user_writable(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $contentItem = ContentItem::factory()->create();
        $story = Story::factory()->create();

        $this->assertFalse($admin->can('create', ContentItem::class));
        $this->assertFalse($admin->can('update', $contentItem));
        $this->assertFalse($admin->can('delete', $contentItem));
        $this->assertFalse($admin->can('create', Story::class));
        $this->assertFalse($admin->can('update', $story));
        $this->assertFalse($admin->can('delete', $story));
    }

    public function test_ai_outputs_are_correctable_by_staff_but_never_creatable_or_deletable(): void
    {
        // DP-004 human-in-the-loop: corrections only, via monitoring.manage.
        $analyst = $this->makeUser(RoleName::Analyst);
        $mention = Mention::factory()->create();
        $detection = RecognitionDetection::factory()->create();
        $sentiment = SentimentAnalysis::factory()->create();

        $this->assertTrue($analyst->can('update', $mention));
        $this->assertTrue($analyst->can('update', $detection));
        $this->assertTrue($analyst->can('update', $sentiment));

        $this->assertFalse($analyst->can('create', Mention::class));
        $this->assertFalse($analyst->can('delete', $mention));
        $this->assertFalse($analyst->can('create', RecognitionDetection::class));
        $this->assertFalse($analyst->can('delete', $detection));
        $this->assertFalse($analyst->can('create', SentimentAnalysis::class));
        $this->assertFalse($analyst->can('delete', $sentiment));
    }

    public function test_metric_snapshots_are_immutable_even_for_admin(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $snapshot = MetricSnapshot::factory()->create();

        $this->assertTrue($admin->can('viewAny', MetricSnapshot::class));
        $this->assertFalse($admin->can('create', MetricSnapshot::class));
        $this->assertFalse($admin->can('update', $snapshot));
        $this->assertFalse($admin->can('delete', $snapshot));
    }

    public function test_comments_are_fully_denied_in_v1(): void
    {
        // DEF-005: no comment feature ships in v1 — even viewing is denied.
        $admin = $this->makeUser(RoleName::Admin);
        $comment = Comment::factory()->create();

        $this->assertFalse($admin->can('viewAny', Comment::class));
        $this->assertFalse($admin->can('view', $comment));
        $this->assertFalse($admin->can('create', Comment::class));
        $this->assertFalse($admin->can('update', $comment));
        $this->assertFalse($admin->can('delete', $comment));
    }
}
