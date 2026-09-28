<?php

namespace Tests\Feature;

use App\Http\Controllers\FaqController;
use App\Http\Controllers\ViewController;
use App\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FaqCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_bisa_menambah_membaca_mengubah_dan_menghapus_faq(): void
    {
        $controller = new FaqController;

        // CREATE
        $createResponse = $controller->store(Request::create('/api/faq/create', 'POST', [
            'question' => 'Bagaimana cara mendaftar?',
            'answer' => 'Isi formulir pendaftaran di halaman SPMB.',
            'sort_order' => 2,
            'is_active' => true,
        ]));

        $this->assertSame(201, $createResponse->getStatusCode());
        $created = $createResponse->getData(true);
        $this->assertTrue($created['success']);
        $this->assertSame('Bagaimana cara mendaftar?', $created['data']['question']);
        $this->assertSame(1, Faq::count());

        $id = $created['data']['id'];

        // READ (index & show)
        $index = $controller->index()->getData(true);
        $this->assertTrue($index['success']);
        $this->assertCount(1, $index['data']);

        $show = $controller->show($id)->getData(true);
        $this->assertTrue($show['success']);
        $this->assertSame($id, $show['data']['id']);

        // UPDATE
        $updateResponse = $controller->update(Request::create("/api/faq/{$id}/update", 'PUT', [
            'question' => 'Bagaimana cara mendaftar? (revisi)',
            'answer' => 'Isi formulir di halaman SPMB.',
            'sort_order' => 5,
            'is_active' => false,
        ]), $id);

        $this->assertSame(200, $updateResponse->getStatusCode());
        $updated = $updateResponse->getData(true);
        $this->assertSame('Bagaimana cara mendaftar? (revisi)', $updated['data']['question']);
        $this->assertSame(5, $updated['data']['sort_order']);
        $this->assertFalse($updated['data']['is_active']);

        // FAQ non-aktif tidak muncul di endpoint publik
        $public = (new ViewController)->getFaq()->getData(true);
        $this->assertCount(0, $public['data']);

        // DELETE
        $deleteResponse = $controller->destroy($id);
        $this->assertSame(200, $deleteResponse->getStatusCode());
        $this->assertSame(0, Faq::count());
    }

    public function test_validasi_menolak_pertanyaan_dan_jawaban_kosong(): void
    {
        $controller = new FaqController;

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $controller->store(Request::create('/api/faq/create', 'POST', [
            'question' => '',
            'answer' => '',
        ]));
    }

    public function test_urutan_tampil_mengikuti_sort_order(): void
    {
        Faq::create(['question' => 'B', 'answer' => 'b', 'sort_order' => 2, 'is_active' => true]);
        Faq::create(['question' => 'A', 'answer' => 'a', 'sort_order' => 1, 'is_active' => true]);

        $data = (new FaqController)->index()->getData(true)['data'];

        $this->assertSame('A', $data[0]['question']);
        $this->assertSame('B', $data[1]['question']);
    }
}
