<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_ticket_pages_and_filter_tickets(): void
    {
        $ticket = SupportTicket::create($this->ticketData());
        SupportTicket::create(array_merge($this->ticketData(), ['title' => 'Another issue', 'reported_by' => 'Other reporter', 'status' => 'in_progress']));
        $this->asAdmin()->get(route('support-tickets.index'))
            ->assertOk()->assertSee('Support Ticketing')->assertSee('Website unavailable')->assertSee('Another issue');
        $this->get(route('support-tickets.index', ['search' => 'Jane', 'status' => 'open']))
            ->assertOk()->assertSee('Website unavailable')->assertDontSee('Another issue');
        $this->get(route('support-tickets.create'))->assertOk()->assertSee('multipart/form-data', false);
        $this->get(route('support-tickets.show', $ticket))->assertOk()->assertSee('The homepage returns an error.');
    }

    public function test_admin_can_create_a_ticket_with_private_s3_attachments(): void
    {
        Storage::fake('s3');
        $this->asAdmin()->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('error.txt', 10, 'text/plain')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $ticket = SupportTicket::firstOrFail();
        $this->assertSame('Jane Smith', $ticket->reported_by);
        $this->assertNotNull($ticket->created_by);
        $attachment = $ticket->attachments->first();
        Storage::disk('s3')->assertExists($attachment->path);
        $this->get(route('support-tickets.attachments.download', [$ticket, $attachment]))->assertDownload('error.txt');
    }

    public function test_upload_uses_private_visibility_and_bucket_owner_acl(): void
    {
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('putFileAs')->once()->withArgs(function ($directory, $file, $filename, $options) {
            $this->assertSame('private', $options['visibility']);
            $this->assertSame('bucket-owner-full-control', $options['ACL']);
            $this->assertSame('application/octet-stream', $options['ContentType']);

            return true;
        })->andReturn('support-tickets/1/private.txt');
        $this->asAdmin()->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('private.txt', 1, 'text/plain')],
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_real_s3_adapter_sends_private_bucket_owner_upload(): void
    {
        $commands = [];
        config(['filesystems.disks.s3' => [
            'driver' => 's3', 'key' => 'test-key', 'secret' => 'test-secret',
            'region' => 'us-east-1', 'bucket' => 'test-ticket-bucket', 'throw' => true,
            'handler' => function ($command) use (&$commands) {
                $commands[] = $command;

                return new \GuzzleHttp\Promise\FulfilledPromise(new \Aws\Result(['ETag' => 'test-etag']));
            },
        ]]);
        Storage::forgetDisk('s3');
        $this->asAdmin()->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('error.txt', 1, 'text/plain')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertCount(1, $commands);
        $this->assertSame('PutObject', $commands[0]->getName());
        $this->assertSame('test-ticket-bucket', $commands[0]['Bucket']);
        $this->assertSame('bucket-owner-full-control', $commands[0]['ACL']);
        $this->assertSame('application/octet-stream', $commands[0]['ContentType']);
        $this->assertSame(SupportTicket::first()->attachments->first()->path, $commands[0]['Key']);
    }

    public function test_update_can_add_attachments_without_replacing_existing_files(): void
    {
        Storage::fake('s3');
        $this->asAdmin()->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('first.txt', 1, 'text/plain')],
        ])->assertSessionHasNoErrors();
        $ticket = SupportTicket::firstOrFail();
        $firstPath = $ticket->attachments->first()->path;
        $this->put(route('support-tickets.update', $ticket), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('second.txt', 1, 'text/plain')],
        ])->assertSessionHasNoErrors();
        $this->assertCount(2, $ticket->fresh()->attachments);
        Storage::disk('s3')->assertExists($firstPath);
    }

    public function test_failed_update_preserves_existing_ticket_details(): void
    {
        $ticket = SupportTicket::create($this->ticketData());
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->andReturn(true);
        $this->asAdmin()->put(route('support-tickets.update', $ticket), array_merge($this->ticketData(), [
            'title' => 'Changed title', 'attachments' => [UploadedFile::fake()->create('failed.txt', 1, 'text/plain')],
        ]))->assertSessionHasErrors('attachments');
        $this->assertSame('Website unavailable', $ticket->fresh()->title);
        $this->assertCount(0, $ticket->fresh()->attachments);
    }

    public function test_admin_can_resolve_and_reopen_a_ticket(): void
    {
        $ticket = SupportTicket::create($this->ticketData());
        $this->asAdmin()->put(route('support-tickets.update', $ticket), array_merge($this->ticketData(), [
            'status' => 'resolved', 'started_at' => '2026-10-09T09:00', 'finished_at' => '2026-10-09T10:00',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('resolved', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->finished_at);

        $this->put(route('support-tickets.update', $ticket), $this->ticketData())->assertSessionHasNoErrors();
        $this->assertNull($ticket->fresh()->finished_at);
    }

    public function test_finish_cannot_precede_start_and_resolved_requires_times(): void
    {
        $this->asAdmin()->post(route('support-tickets.store'), array_merge($this->ticketData(), [
            'status' => 'resolved', 'started_at' => '2026-10-09T10:00', 'finished_at' => '2026-10-09T09:00',
        ]))->assertSessionHasErrors('finished_at');
        $this->post(route('support-tickets.store'), array_merge($this->ticketData(), ['status' => 'resolved']))
            ->assertSessionHasErrors(['started_at', 'finished_at']);
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_unsafe_oversized_and_excessive_attachments_are_rejected(): void
    {
        $this->asAdmin()->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('script.php', 1, 'text/plain')],
        ])->assertSessionHasErrors('attachments.0');
        $this->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')],
        ])->assertSessionHasErrors('attachments.0');
        $this->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => array_map(fn ($number) => UploadedFile::fake()->create('file'.$number.'.txt', 1, 'text/plain'), range(1, 6)),
        ])->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_failed_upload_rolls_back_ticket_and_cleans_up_objects(): void
    {
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $filename) {
            $this->assertStringStartsWith('support-tickets/', $directory);

            return $directory.'/'.$filename;
        });
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->with(\Mockery::on(fn ($paths) => count($paths) === 2))->andReturn(true);

        $this->asAdmin()->post(route('support-tickets.store'), $this->ticketData() + [
            'attachments' => [UploadedFile::fake()->create('one.txt', 1, 'text/plain'), UploadedFile::fake()->create('two.txt', 1, 'text/plain')],
        ])->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_ticket_attachments', 0);
    }

    public function test_guest_and_viewer_cannot_access_ticketing(): void
    {
        $this->get(route('support-tickets.index'))->assertRedirect(route('login'));
        $viewer = User::factory()->create(['role' => 'viewer', 'two_factor_confirmed_at' => now()]);
        $this->withSession(['mfa.verified_user' => $viewer->id])->actingAs($viewer);
        $this->get(route('support-tickets.index'))->assertForbidden();
        $this->post(route('support-tickets.store'), $this->ticketData())->assertForbidden();
    }

    public function test_attachment_must_belong_to_ticket(): void
    {
        $ticket = SupportTicket::create($this->ticketData());
        $other = SupportTicket::create($this->ticketData());
        $attachment = $other->attachments()->create(['original_name' => 'error.txt', 'path' => 'support-tickets/other.txt', 'mime_type' => 'text/plain', 'size' => 10]);
        $this->asAdmin()->get(route('support-tickets.attachments.download', [$ticket, $attachment]))->assertNotFound();
    }

    private function asAdmin(): static
    {
        $admin = User::factory()->create(['role' => 'admin', 'two_factor_confirmed_at' => now()]);

        return $this->withSession(['mfa.verified_user' => $admin->id])->actingAs($admin);
    }

    private function ticketData(): array
    {
        return ['title' => 'Website unavailable', 'issue' => 'The homepage returns an error.', 'reported_by' => 'Jane Smith', 'status' => 'open'];
    }

    public function test_ticket_details_and_attachment_metadata_are_persisted(): void
    {
        $ticket = SupportTicket::create([
            'title' => 'Website unavailable',
            'issue' => 'The homepage returns an error.',
            'reported_by' => 'Jane Smith',
            'status' => 'resolved',
            'started_at' => '2026-10-09 09:00:00',
            'finished_at' => '2026-10-09 10:00:00',
        ]);
        $ticket->attachments()->create([
            'original_name' => 'error.png',
            'path' => 'support-tickets/1/random.png',
            'mime_type' => 'image/png',
            'size' => 100,
        ]);

        $this->assertSame('Jane Smith', $ticket->fresh()->reported_by);
        $this->assertSame('2026-10-09 10:00:00', $ticket->fresh()->finished_at->format('Y-m-d H:i:s'));
        $this->assertSame('error.png', $ticket->fresh()->attachments->first()->original_name);
    }
}
