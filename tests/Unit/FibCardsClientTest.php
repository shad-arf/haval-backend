<?php

namespace Tests\Unit;

use App\Services\FibCardsClient;
use App\Services\FibPaymentReconciler;
use PHPUnit\Framework\TestCase;

class FibCardsClientTest extends TestCase
{
    public function test_canonical_json_sorts_keys_case_insensitively(): void
    {
        $json = FibCardsClient::canonicalJson(['Zebra' => 1, 'apple' => 2, 'Mango' => 3]);

        $this->assertSame('{"apple":2,"Mango":3,"Zebra":1}', $json);
    }

    public function test_canonical_json_sorts_nested_objects_too(): void
    {
        $json = FibCardsClient::canonicalJson([
            'orderId' => 'X',
            'clientInfo' => ['mobilePhone' => '07', 'clientName' => 'A', 'email' => 'e'],
        ]);

        $this->assertSame('{"clientInfo":{"clientName":"A","email":"e","mobilePhone":"07"},"orderId":"X"}', $json);
    }

    /**
     * Lists must keep their order — sorting them would change the meaning of
     * the payload, not just its spelling.
     */
    public function test_canonical_json_leaves_lists_untouched(): void
    {
        $json = FibCardsClient::canonicalJson(['items' => ['c', 'a', 'b']]);

        $this->assertSame('{"items":["c","a","b"]}', $json);
    }

    /**
     * PHP escapes slashes and unicode by default; JavaScript does not. The two
     * must agree byte for byte or FIB rejects the signature.
     */
    public function test_canonical_json_does_not_escape_slashes(): void
    {
        $json = FibCardsClient::canonicalJson(['redirectUrl' => 'https://a.test/return']);

        $this->assertSame('{"redirectUrl":"https://a.test/return"}', $json);
        $this->assertStringNotContainsString('\\/', $json);
    }

    public function test_the_signature_is_hex_hmac_sha512_of_the_canonical_body(): void
    {
        $json = FibCardsClient::canonicalJson(['b' => 2, 'a' => 1]);

        $this->assertSame(hash_hmac('sha512', $json, 'secret'), FibCardsClient::sign($json, 'secret'));
        $this->assertSame(128, strlen(FibCardsClient::sign($json, 'secret')));
    }

    public function test_a_different_secret_produces_a_different_signature(): void
    {
        $json = FibCardsClient::canonicalJson(['a' => 1]);

        $this->assertNotSame(FibCardsClient::sign($json, 'one'), FibCardsClient::sign($json, 'two'));
    }

    /**
     * FIB answers 401 "Invalid hmac signature" to any body containing a
     * non-ASCII byte, so Arabic and Kurdish names are folded before signing.
     */
    public function test_arabic_text_is_folded_to_ascii(): void
    {
        $folded = FibCardsClient::ascii('شاد عبدالله', 'Customer');

        $this->assertSame('', preg_replace('/[\x20-\x7E]/', '', $folded), 'Folded text must be pure ASCII.');
        $this->assertNotSame('', $folded);
    }

    public function test_accented_latin_is_transliterated_rather_than_dropped(): void
    {
        $this->assertSame('Renas', FibCardsClient::ascii('Rénas'));
    }

    public function test_text_that_folds_away_entirely_falls_back(): void
    {
        $this->assertSame('Customer', FibCardsClient::ascii('؟؟؟', 'Customer'));
        $this->assertSame('Customer', FibCardsClient::ascii('', 'Customer'));
    }

    public function test_whitespace_is_collapsed(): void
    {
        $this->assertSame('Ali Hassan', FibCardsClient::ascii("  Ali   \n Hassan  "));
    }

    public function test_clamp_respects_multibyte_characters(): void
    {
        $this->assertSame('abc', FibCardsClient::clamp('abcdef', 3));
        $this->assertSame('شاد', FibCardsClient::clamp('شاد عبدالله', 3));
    }

    public function test_the_date_format_is_fibs_baghdad_format(): void
    {
        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}$#', FibCardsClient::now());
    }

    /**
     * The critical UAT finding: opening the card page creates the transaction
     * with status ERROR before the customer types anything. Treating that as a
     * decline would fail every order at the moment the customer arrives.
     */
    public function test_error_is_an_in_progress_state_not_a_failure(): void
    {
        $this->assertContains('ERROR', FibCardsClient::IN_PROGRESS_STATES);
        $this->assertNotContains('ERROR', FibCardsClient::FAILED_STATES);
        $this->assertNotContains('ERROR', FibCardsClient::PAID_STATES);
    }

    public function test_the_state_sets_do_not_overlap(): void
    {
        $sets = [
            FibCardsClient::PAID_STATES,
            FibCardsClient::FAILED_STATES,
            FibCardsClient::CANCELLED_STATES,
            FibCardsClient::IN_PROGRESS_STATES,
        ];

        $all = array_merge(...$sets);

        $this->assertSame(count($all), count(array_unique($all)), 'A state must belong to exactly one set.');
    }

    public function test_it_reads_the_state_from_payment_data(): void
    {
        $this->assertSame('SUCCESS', FibPaymentReconciler::readState(['paymentData' => ['status' => 'success']]));
        $this->assertSame('SUCCESS', FibPaymentReconciler::readState(['status' => 'SUCCESS']));
        $this->assertSame('PENDING', FibPaymentReconciler::readState(['paymentData' => ['paymentStatus' => 'Pending']]));
        $this->assertSame('', FibPaymentReconciler::readState([]));
    }
}
