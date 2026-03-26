<?php

namespace Tests\Unit\Services;

use App\Services\FilePreValidationService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilePreValidationServiceTest extends TestCase
{
    private FilePreValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FilePreValidationService();
    }

    // --- validateHeaders() unit tests ---

    public function test_valid_headers_pass(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban', 'amount']);

        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
        $this->assertEmpty($result['suggestions']);
    }

    public function test_valid_headers_with_aliases_pass(): void
    {
        $result = $this->service->validateHeaders(['fullname', 'bank_account', 'sum', 'email']);

        $this->assertEmpty($result['errors']);
    }

    public function test_missing_iban_header_returns_error(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'amount']);

        $this->assertContains('Missing required header: IBAN.', $result['errors']);
    }

    public function test_missing_amount_header_returns_error(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban']);

        $this->assertContains('Missing required header: amount.', $result['errors']);
    }

    public function test_missing_all_name_headers_returns_error(): void
    {
        $result = $this->service->validateHeaders(['iban', 'amount', 'email']);

        $this->assertContains("Missing required header: first_name, last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.", $result['errors']);
    }

    public function test_misspelled_header_as_only_name_header_returns_error_and_suggestion(): void
    {
        // "lst_name" is misspelled — first_name alone doesn't satisfy (needs both first_name + last_name)
        $result = $this->service->validateHeaders(['first_name', 'lst_name', 'iban', 'amount']);

        $this->assertContains("Missing required header: last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.", $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertArrayHasKey('lst_name', $result['suggestions']);
        $this->assertEquals('last_name', $result['suggestions']['lst_name']);
    }

    public function test_misspelled_header_without_any_valid_name_returns_error_and_suggestion(): void
    {
        // Only misspelled name headers — no valid name column
        $result = $this->service->validateHeaders(['frst_name', 'lst_name', 'iban', 'amount']);

        $this->assertContains("Missing required header: first_name, last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.", $result['errors']);
        $this->assertNotEmpty($result['suggestions']);
    }

    public function test_misspelled_amount_returns_error_and_suggestion(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban', 'amout']);

        $this->assertContains('Missing required header: amount.', $result['errors']);
        $this->assertArrayHasKey('amout', $result['suggestions']);
        $this->assertEquals('amount', $result['suggestions']['amout']);
    }

    public function test_completely_unknown_header_no_suggestion(): void
    {
        // "foobar" is far from any known header
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban', 'amount', 'foobar']);

        $this->assertEmpty($result['errors']);
        $this->assertArrayNotHasKey('foobar', $result['suggestions']);
    }

    public function test_multiple_missing_required_headers(): void
    {
        $result = $this->service->validateHeaders(['email', 'city']);

        $this->assertCount(3, $result['errors']);
        $this->assertContains('Missing required header: IBAN.', $result['errors']);
        $this->assertContains('Missing required header: amount.', $result['errors']);
        $this->assertContains("Missing required header: first_name, last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.", $result['errors']);
    }

    public function test_empty_header_is_ignored(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', '', 'iban', 'amount']);

        $this->assertEmpty($result['errors']);
    }

    // --- Full CSV file validation tests ---

    public function test_valid_csv_file_passes(): void
    {
        $path = $this->createTempCsv("first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00");
        $file = $this->createUploadedFile($path, 'valid.csv');

        $result = $this->service->validate($file);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertGreaterThan(0, $result['sample_count']);

        unlink($path);
    }

    public function test_csv_with_misspelled_header_returns_warnings_and_suggestions(): void
    {
        $path = $this->createTempCsv("name,lst_name,iban,amount\nJohn Doe,Doe,DE89370400440532013000,100.00");
        $file = $this->createUploadedFile($path, 'typo.csv');

        $result = $this->service->validate($file);

        // 'name' covers the name requirement, so file is valid but with warnings for lst_name
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('last_name', $result['suggestions']['lst_name']);

        unlink($path);
    }

    public function test_csv_missing_all_required_returns_422_errors(): void
    {
        $path = $this->createTempCsv("foo,bar\nbaz,qux");
        $file = $this->createUploadedFile($path, 'bad.csv');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertCount(3, $result['errors']);

        unlink($path);
    }

    public function test_empty_csv_returns_error(): void
    {
        $path = $this->createTempCsv('');
        $file = $this->createUploadedFile($path, 'empty.csv');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertContains('File is empty or has no headers.', $result['errors']);

        unlink($path);
    }

    public function test_csv_headers_only_no_data_rows(): void
    {
        $path = $this->createTempCsv("first_name,last_name,iban,amount\n");
        $file = $this->createUploadedFile($path, 'headers_only.csv');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertContains('File has headers but no data rows.', $result['errors']);

        unlink($path);
    }

    public function test_semicolon_delimited_csv_passes(): void
    {
        $path = $this->createTempCsv("first_name;last_name;iban;amount\nJohn;Doe;DE89370400440532013000;100.00");
        $file = $this->createUploadedFile($path, 'semicolon.csv');

        $result = $this->service->validate($file);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        unlink($path);
    }

    public function test_excel_file_with_valid_headers_passes(): void
    {
        $path = $this->createTempExcel([
            ['First Name', 'Last Name', 'IBAN', 'Amount'],
            ['John', 'Doe', 'DE89370400440532013000', 100.00],
        ]);
        $file = $this->createUploadedFile($path, 'valid.xlsx');

        $result = $this->service->validate($file);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        unlink($path);
    }

    public function test_excel_file_with_misspelled_header_returns_suggestion(): void
    {
        $path = $this->createTempExcel([
            ['Name', 'Lst Name', 'IBAN', 'Amount'],
            ['John Doe', 'Doe', 'DE89370400440532013000', 100.00],
        ]);
        $file = $this->createUploadedFile($path, 'typo.xlsx');

        $result = $this->service->validate($file);

        // 'name' covers the name requirement; lst_name triggers warning
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertArrayHasKey('lst_name', $result['suggestions']);

        unlink($path);
    }

    public function test_unsupported_file_type_returns_error(): void
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.pdf';
        file_put_contents($path, 'dummy');
        $file = $this->createUploadedFile($path, 'bad.pdf');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertContains('Unsupported file type.', $result['errors']);

        unlink($path);
    }

    public function test_result_always_contains_all_keys(): void
    {
        $path = $this->createTempCsv("first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100");
        $file = $this->createUploadedFile($path, 'valid.csv');

        $result = $this->service->validate($file);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('sample_count', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('suggestions', $result);

        unlink($path);
    }

    // =====================================================================
    // NAME HEADER VALIDATION — data-provider tests
    // =====================================================================

    public static function nameValidProvider(): array
    {
        return [
            'first_name + last_name'   => [['first_name', 'last_name']],
            'firstname + lastname'     => [['firstname', 'lastname']],
            'firstname + last_name'    => [['firstname', 'last_name']],
            'first_name + lastname'    => [['first_name', 'lastname']],
            'first_name + surname'     => [['first_name', 'surname']],
            'firstname + surname'      => [['firstname', 'surname']],
            'name'                     => [['name']],
            'full_name'                => [['full_name']],
            'fullname'                 => [['fullname']],
            'customer_name'            => [['customer_name']],
            'debtor_name'              => [['debtor_name']],
            'client_name'              => [['client_name']],
            'account_holder'           => [['account_holder']],
        ];
    }

    #[DataProvider('nameValidProvider')]
    public function test_name_valid_headers_pass(array $nameHeaders): void
    {
        $headers = array_merge($nameHeaders, ['iban', 'amount']);
        $result = $this->service->validateHeaders($headers);

        $this->assertEmpty($result['errors'], 'Expected no errors for valid name headers: ' . implode(', ', $nameHeaders));
    }

    public static function namePartialProvider(): array
    {
        // A single first/last name field does NOT satisfy the requirement — need both or a full-name field
        return [
            'first_name only (no last_name)'     => [['first_name'], "Missing required header: last_name. Provide a 'name' column, or both 'first_name' and 'last_name'."],
            'last_name only (no first_name)'      => [['last_name'], "Missing required header: first_name. Provide a 'name' column, or both 'first_name' and 'last_name'."],
            'firstname only (no last_name)'       => [['firstname'], "Missing required header: last_name. Provide a 'name' column, or both 'first_name' and 'last_name'."],
            'lastname only (no first_name)'       => [['lastname'], "Missing required header: first_name. Provide a 'name' column, or both 'first_name' and 'last_name'."],
            'surname only (no first_name)'        => [['surname'], "Missing required header: first_name. Provide a 'name' column, or both 'first_name' and 'last_name'."],
        ];
    }

    #[DataProvider('namePartialProvider')]
    public function test_name_partial_headers_still_pass(array $nameHeaders, string $expectedError): void
    {
        $headers = array_merge($nameHeaders, ['iban', 'amount']);
        $result = $this->service->validateHeaders($headers);

        $this->assertContains($expectedError, $result['errors'], 'Expected name error for partial headers: ' . implode(', ', $nameHeaders));
    }

    public function test_name_missing_entirely_returns_error(): void
    {
        $result = $this->service->validateHeaders(['iban', 'amount', 'email']);

        $this->assertContains("Missing required header: first_name, last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.", $result['errors']);
    }

    public static function nameMisspelledFailProvider(): array
    {
        // Each row: [headers, expectedMissingError, expectedSuggestions, expectedErrorMessage]
        // The service requires BOTH first_name AND last_name, or a full-name field.
        // When one side is valid and the other misspelled, an error is still produced for the missing side.
        $bothMissing = "Missing required header: first_name, last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.";
        $missingFirst = "Missing required header: first_name. Provide a 'name' column, or both 'first_name' and 'last_name'.";
        $missingLast = "Missing required header: last_name. Provide a 'name' column, or both 'first_name' and 'last_name'.";

        return [
            'frst_name + last_name'        => [['frst_name', 'last_name'], true, ['frst_name' => 'first_name'], $missingFirst],
            'frist_name + last_name'       => [['frist_name', 'last_name'], true, ['frist_name' => 'first_name'], $missingFirst],
            'first_nme + last_name'        => [['first_nme', 'last_name'], true, ['first_nme' => 'first_name'], $missingFirst],
            'first_name + lst_name'        => [['first_name', 'lst_name'], true, ['lst_name' => 'last_name'], $missingLast],
            'first_name + lst_nme'         => [['first_name', 'lst_nme'], true, ['lst_nme' => 'last_name'], $missingLast],
            'first_name + lastt_name'      => [['first_name', 'lastt_name'], true, ['lastt_name' => 'last_name'], $missingLast],
            'first_name + surnme'          => [['first_name', 'surnme'], true, ['surnme' => 'surname'], $missingLast],
            'first_name + surnam'          => [['first_name', 'surnam'], true, ['surnam' => 'surname'], $missingLast],
            'frst_name + lst_nme (both)'   => [['frst_name', 'lst_nme'], true, ['frst_name' => 'first_name', 'lst_nme' => 'last_name'], $bothMissing],
            'frist_name + lst_name (both)' => [['frist_name', 'lst_name'], true, ['frist_name' => 'first_name', 'lst_name' => 'last_name'], $bothMissing],
            'ful_name'                     => [['ful_name'], true, ['ful_name' => 'full_name'], $bothMissing],
            'fulll_name'                   => [['fulll_name'], true, ['fulll_name' => 'full_name'], $bothMissing],
            'full_nme'                     => [['full_nme'], true, ['full_nme' => 'full_name'], $bothMissing],
            'fullnme'                      => [['fullnme'], true, ['fullnme' => 'fullname'], $bothMissing],
            'fulname'                      => [['fulname'], true, ['fulname' => 'fullname'], $bothMissing],
            'custmer_name'                 => [['custmer_name'], true, ['custmer_name' => 'customer_name'], $bothMissing],
            'customer_nme'                 => [['customer_nme'], true, ['customer_nme' => 'customer_name'], $bothMissing],
            'costumer_name'                => [['costumer_name'], true, ['costumer_name' => 'customer_name'], $bothMissing],
            'debtr_name'                   => [['debtr_name'], true, ['debtr_name' => 'debtor_name'], $bothMissing],
            'debtor_nme'                   => [['debtor_nme'], true, ['debtor_nme' => 'debtor_name'], $bothMissing],
            'debtorr_name'                 => [['debtorr_name'], true, ['debtorr_name' => 'debtor_name'], $bothMissing],
            'cleint_name'                  => [['cleint_name'], true, ['cleint_name' => 'client_name'], $bothMissing],
            'clint_name'                   => [['clint_name'], true, ['clint_name' => 'client_name'], $bothMissing],
            'client_nme'                   => [['client_nme'], true, ['client_nme' => 'client_name'], $bothMissing],
            'acount_holder'                => [['acount_holder'], true, ['acount_holder' => 'account_holder'], $bothMissing],
            'account_holdr'                => [['account_holdr'], true, ['account_holdr' => 'account_holder'], $bothMissing],
            'acccount_holder'              => [['acccount_holder'], true, ['acccount_holder' => 'account_holder'], $bothMissing],
        ];
    }

    #[DataProvider('nameMisspelledFailProvider')]
    public function test_name_misspelled_headers(array $nameHeaders, bool $expectMissingError, array $expectedSuggestions, string $expectedErrorMessage): void
    {
        $headers = array_merge($nameHeaders, ['iban', 'amount']);
        $result = $this->service->validateHeaders($headers);

        if ($expectMissingError) {
            $this->assertContains($expectedErrorMessage, $result['errors'],
                'Expected missing-name error for headers: ' . implode(', ', $nameHeaders));
        } else {
            $this->assertEmpty(
                array_filter($result['errors'], fn ($e) => str_contains($e, "Provide a 'name' column")),
                'Did not expect missing-name error — a valid name header is present alongside the typo'
            );
        }

        foreach ($expectedSuggestions as $typo => $expected) {
            $this->assertArrayHasKey($typo, $result['suggestions'], "Expected suggestion for '{$typo}'");
            $this->assertEquals($expected, $result['suggestions'][$typo], "Expected '{$typo}' to suggest '{$expected}'");
        }

        $this->assertNotEmpty($result['warnings'], 'Expected at least one warning for misspelled header');
    }

    // =====================================================================
    // IBAN HEADER VALIDATION — data-provider tests
    // =====================================================================

    public static function ibanValidProvider(): array
    {
        return [
            'iban'           => [['iban']],
            'iban_number'    => [['iban_number']],
            'bank_account'   => [['bank_account']],
            'account_number' => [['account_number']],
        ];
    }

    #[DataProvider('ibanValidProvider')]
    public function test_iban_valid_headers_pass(array $ibanHeaders): void
    {
        $headers = array_merge($ibanHeaders, ['name', 'amount']);
        $result = $this->service->validateHeaders($headers);

        $this->assertEmpty($result['errors'], 'Expected no errors for valid IBAN header: ' . implode(', ', $ibanHeaders));
    }

    public function test_iban_missing_entirely_returns_error(): void
    {
        $result = $this->service->validateHeaders(['name', 'amount', 'email']);

        $this->assertContains('Missing required header: IBAN.', $result['errors']);
    }

    public static function ibanMisspelledProvider(): array
    {
        return [
            'ibn'              => [['ibn'], ['ibn' => 'iban']],
            'iiban'            => [['iiban'], ['iiban' => 'iban']],
            'iba'              => [['iba'], ['iba' => 'iban']],
            'ibann'            => [['ibann'], ['ibann' => 'iban']],
            'iban_numbr'       => [['iban_numbr'], ['iban_numbr' => 'iban_number']],
            'iiban_number'     => [['iiban_number'], ['iiban_number' => 'iban_number']],
            'iban_numer'       => [['iban_numer'], ['iban_numer' => 'iban_number']],
            'iban_numbre'      => [['iban_numbre'], ['iban_numbre' => 'iban_number']],
            'bnk_account'      => [['bnk_account'], ['bnk_account' => 'bank_account']],
            'bank_acount'      => [['bank_acount'], ['bank_acount' => 'bank_account']],
            'bank_acccount'    => [['bank_acccount'], ['bank_acccount' => 'bank_account']],
            'banck_account'    => [['banck_account'], ['banck_account' => 'bank_account']],
            'acount_number'    => [['acount_number'], ['acount_number' => 'account_number']],
            'account_numbr'    => [['account_numbr'], ['account_numbr' => 'account_number']],
            'account_numer'    => [['account_numer'], ['account_numer' => 'account_number']],
            'acccount_number'  => [['acccount_number'], ['acccount_number' => 'account_number']],
        ];
    }

    #[DataProvider('ibanMisspelledProvider')]
    public function test_iban_misspelled_headers(array $ibanHeaders, array $expectedSuggestions): void
    {
        $headers = array_merge($ibanHeaders, ['name', 'amount']);
        $result = $this->service->validateHeaders($headers);

        $this->assertContains('Missing required header: IBAN.', $result['errors'],
            'Expected missing-IBAN error for header: ' . implode(', ', $ibanHeaders));

        foreach ($expectedSuggestions as $typo => $expected) {
            $this->assertArrayHasKey($typo, $result['suggestions'], "Expected suggestion for '{$typo}'");
            $this->assertEquals($expected, $result['suggestions'][$typo], "Expected '{$typo}' to suggest '{$expected}'");
        }

        $this->assertNotEmpty($result['warnings']);
    }

    // =====================================================================
    // AMOUNT HEADER VALIDATION — data-provider tests
    // =====================================================================

    public static function amountValidProvider(): array
    {
        return [
            'amount' => [['amount']],
            'sum'    => [['sum']],
            'total'  => [['total']],
            'price'  => [['price']],
        ];
    }

    #[DataProvider('amountValidProvider')]
    public function test_amount_valid_headers_pass(array $amountHeaders): void
    {
        $headers = array_merge($amountHeaders, ['name', 'iban']);
        $result = $this->service->validateHeaders($headers);

        $this->assertEmpty($result['errors'], 'Expected no errors for valid amount header: ' . implode(', ', $amountHeaders));
    }

    public function test_amount_missing_entirely_returns_error(): void
    {
        $result = $this->service->validateHeaders(['name', 'iban', 'email']);

        $this->assertContains('Missing required header: amount.', $result['errors']);
    }

    public static function amountMisspelledProvider(): array
    {
        return [
            'amont'   => [['amont'], ['amont' => 'amount']],
            'ammount' => [['ammount'], ['ammount' => 'amount']],
            'amoun'   => [['amoun'], ['amoun' => 'amount']],
            'amoutn'  => [['amoutn'], ['amoutn' => 'amount']],
            'summ'    => [['summ'], ['summ' => 'sum']],
            'suum'    => [['suum'], ['suum' => 'sum']],
            'totl'    => [['totl'], ['totl' => 'total']],
            'ttal'    => [['ttal'], ['ttal' => 'total']],
            'totel'   => [['totel'], ['totel' => 'total']],
            'totall'  => [['totall'], ['totall' => 'total']],
            'pric'    => [['pric'], ['pric' => 'price']],
            'priice'  => [['priice'], ['priice' => 'price']],
            'rpice'   => [['rpice'], ['rpice' => 'price']],
            'prce'    => [['prce'], ['prce' => 'price']],
        ];
    }

    #[DataProvider('amountMisspelledProvider')]
    public function test_amount_misspelled_headers(array $amountHeaders, array $expectedSuggestions): void
    {
        $headers = array_merge($amountHeaders, ['name', 'iban']);
        $result = $this->service->validateHeaders($headers);

        $this->assertContains('Missing required header: amount.', $result['errors'],
            'Expected missing-amount error for header: ' . implode(', ', $amountHeaders));

        foreach ($expectedSuggestions as $typo => $expected) {
            $this->assertArrayHasKey($typo, $result['suggestions'], "Expected suggestion for '{$typo}'");
            $this->assertEquals($expected, $result['suggestions'][$typo], "Expected '{$typo}' to suggest '{$expected}'");
        }

        $this->assertNotEmpty($result['warnings']);
    }

    public function test_amount_misspelled_sm_no_suggestion_due_to_short_length(): void
    {
        $headers = ['name', 'iban', 'sm'];
        $result = $this->service->validateHeaders($headers);

        $this->assertContains('Missing required header: amount.', $result['errors']);
        $this->assertArrayNotHasKey('sm', $result['suggestions'],
            'Headers with length <= 2 should not produce suggestions');
    }

    // --- Helpers ---

    private function createTempCsv(string $content): string
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    private function createTempExcel(array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($data as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    private function createUploadedFile(string $path, string $name): \Illuminate\Http\UploadedFile
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $mimeTypes = [
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'pdf' => 'application/pdf',
        ];

        return new \Illuminate\Http\UploadedFile(
            $path,
            $name,
            $mimeTypes[$extension] ?? 'application/octet-stream',
            null,
            true
        );
    }
}
