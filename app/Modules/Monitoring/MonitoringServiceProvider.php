<?php

namespace App\Modules\Monitoring;

use App\Modules\Monitoring\Contracts\ContentMatchFeedback;
use App\Modules\Monitoring\Contracts\RosterEnrollment;
use App\Modules\Monitoring\Livewire\Dashboard\ContentDetail;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorDetail;
use App\Modules\Monitoring\Livewire\Dashboard\CreatorsIndex;
use App\Modules\Monitoring\Livewire\Dashboard\HomeOverview;
use App\Modules\Monitoring\Livewire\Dashboard\MonitoringOverview;
use App\Modules\Monitoring\Livewire\Emv\EmvSettings;
use App\Modules\Monitoring\Livewire\Exports\ExportsIndex;
use App\Modules\Monitoring\Livewire\Hashtags\HashtagListsIndex;
use App\Modules\Monitoring\Livewire\Operations\MonitoringPlanSettings;
use App\Modules\Monitoring\Livewire\Operations\OperationsDashboard;
use App\Modules\Monitoring\Livewire\Reach\ReachSettings;
use App\Modules\Monitoring\Livewire\Review\ReviewQueueIndex;
use App\Modules\Monitoring\Models\Comment;
use App\Modules\Monitoring\Models\ContentHashtag;
use App\Modules\Monitoring\Models\ContentItem;
use App\Modules\Monitoring\Models\EmvConfiguration;
use App\Modules\Monitoring\Models\EmvResult;
use App\Modules\Monitoring\Models\HashtagList;
use App\Modules\Monitoring\Models\Mention;
use App\Modules\Monitoring\Models\MetricSnapshot;
use App\Modules\Monitoring\Models\MonitoredSubject;
use App\Modules\Monitoring\Models\ReachConfiguration;
use App\Modules\Monitoring\Models\RecognitionDetection;
use App\Modules\Monitoring\Models\ReviewAction;
use App\Modules\Monitoring\Models\SentimentAnalysis;
use App\Modules\Monitoring\Models\Story;
use App\Modules\Monitoring\Policies\CommentPolicy;
use App\Modules\Monitoring\Policies\ContentHashtagPolicy;
use App\Modules\Monitoring\Policies\ContentItemPolicy;
use App\Modules\Monitoring\Policies\EmvConfigurationPolicy;
use App\Modules\Monitoring\Policies\EmvResultPolicy;
use App\Modules\Monitoring\Policies\HashtagListPolicy;
use App\Modules\Monitoring\Policies\MentionPolicy;
use App\Modules\Monitoring\Policies\MetricSnapshotPolicy;
use App\Modules\Monitoring\Policies\MonitoredSubjectPolicy;
use App\Modules\Monitoring\Policies\ReachConfigurationPolicy;
use App\Modules\Monitoring\Policies\RecognitionDetectionPolicy;
use App\Modules\Monitoring\Policies\ReviewActionPolicy;
use App\Modules\Monitoring\Policies\SentimentAnalysisPolicy;
use App\Modules\Monitoring\Policies\StoryPolicy;
use App\Modules\Monitoring\Services\ContentMatchFeedbackRecorder;
use App\Modules\Monitoring\Services\RosterEnrollmentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Module 1 — Monitoring & Reporting (SVC-Monitoring, phase P1).
 * Spec: docs/50-modules/module-1-monitoring.md. Write-owns ContentItem,
 * Story, Comment, MonitoredSubject, Mention, RecognitionDetection,
 * SentimentAnalysis, MetricSnapshot (ownership matrix).
 *
 * The domain foundation (migrations, models, policies) is P0 scope —
 * "every ENT-* migrated and persistable" is a P0 exit criterion. REQ-M1-*
 * behaviour (ingestion, enrichment, dashboards) is buildable when P1 is the
 * active phase.
 */
class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // XMC-002 (module-3 §5): M3 confirms/denies content↔campaign matches;
        // the M1-side recorder is the only writer of mentions.campaign_id
        // (ENT-Mention is M1-owned — ownership matrix).
        $this->app->bind(ContentMatchFeedback::class, ContentMatchFeedbackRecorder::class);

        // Roster enrollment seam: the CRM's creator lifecycle enrolls /
        // withdraws roster subjects through this M1-side writer
        // (ENT-MonitoredSubject is M1-owned — ownership matrix).
        $this->app->bind(RosterEnrollment::class, RosterEnrollmentService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        Gate::policy(MonitoredSubject::class, MonitoredSubjectPolicy::class);
        Gate::policy(ContentItem::class, ContentItemPolicy::class);
        Gate::policy(Story::class, StoryPolicy::class);
        Gate::policy(Comment::class, CommentPolicy::class);
        Gate::policy(Mention::class, MentionPolicy::class);
        Gate::policy(RecognitionDetection::class, RecognitionDetectionPolicy::class);
        Gate::policy(SentimentAnalysis::class, SentimentAnalysisPolicy::class);
        Gate::policy(MetricSnapshot::class, MetricSnapshotPolicy::class);

        // Enrichment surfaces (SVC-EnrichmentAI outputs + configuration).
        Gate::policy(ContentHashtag::class, ContentHashtagPolicy::class);
        Gate::policy(HashtagList::class, HashtagListPolicy::class);
        Gate::policy(ReviewAction::class, ReviewActionPolicy::class);
        Gate::policy(EmvConfiguration::class, EmvConfigurationPolicy::class);
        Gate::policy(EmvResult::class, EmvResultPolicy::class);
        Gate::policy(ReachConfiguration::class, ReachConfigurationPolicy::class);

        Livewire::component('monitoring.review-queue-index', ReviewQueueIndex::class);
        Livewire::component('monitoring.emv-settings', EmvSettings::class);
        Livewire::component('monitoring.reach-settings', ReachSettings::class);
        Livewire::component('monitoring.hashtag-lists-index', HashtagListsIndex::class);
        Livewire::component('monitoring.monitoring-overview', MonitoringOverview::class);
        Livewire::component('monitoring.home-overview', HomeOverview::class);
        Livewire::component('monitoring.creators-index', CreatorsIndex::class);
        Livewire::component('monitoring.creator-detail', CreatorDetail::class);
        Livewire::component('monitoring.content-detail', ContentDetail::class);
        Livewire::component('monitoring.operations-dashboard', OperationsDashboard::class);
        Livewire::component('monitoring.monitoring-plan-settings', MonitoringPlanSettings::class);
        Livewire::component('monitoring.exports-index', ExportsIndex::class);
    }
}
