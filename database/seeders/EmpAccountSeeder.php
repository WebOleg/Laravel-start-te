<?php
/**
 * Seeder for EMP merchant accounts.
 * Populates all 7 production terminals.
 */
namespace Database\Seeders;
use App\Models\EmpAccount;
use Illuminate\Database\Seeder;
class EmpAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Elariosso',
                'slug' => 'elariosso',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => '14cd774ac50e7b4e36450fb3f9e096dc0cfab2da',
                'password' => '131344448670e02e834e8e18d0445c93a4d5efe1',
                'terminal_token' => '5f882a6148de0f4115e1d51d8e9b0d956d23e7b7',
                'is_active' => false,
                'sort_order' => 1,
                'monthly_cap' => 450000,
            ],
            [
                'name' => 'Optivest',
                'slug' => 'optivest',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => 'e87765b5565fcb42370064b3e16db9852ecfa2b6',
                'password' => '5d982aa9b5729748ef1728f49825e779e34e7167',
                'terminal_token' => 'dc037916d0078da32f7d3d509b262b7cd412a7ce',
                'is_active' => false,
                'sort_order' => 2,
                'monthly_cap' => 400000,
            ],
            [
                'name' => 'Lunaro',
                'slug' => 'lunaro',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => 'da52e53b11f53dd4048b13f42c24ca10f5057e46',
                'password' => '9669a5a9d0b61a55f15adae021e141744974374d',
                'terminal_token' => '72a28b55154f93f57bb1d037f565391245a5e3cf',
                'is_active' => true,
                'sort_order' => 3,
                'monthly_cap' => 300000,
            ],
            [
                'name' => 'Corellia Ads',
                'slug' => 'corellia-ads',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => 'd9692352095a97820e8f83b138aa06602ff9a4a0',
                'password' => 'c5444af27d70c4cb59ad8e59eddebfff3e80bb07',
                'terminal_token' => 'f03f41cb47ee21463b2481c8d5f6f272e8599318',
                'is_active' => false,
                'sort_order' => 4,
                'monthly_cap' => 300000,
            ],
            [
                'name' => 'SmartThings Ventures',
                'slug' => 'smartthings-ventures',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => 'e1b9b226d6430d2b36f60c84663707f4a7fea791',
                'password' => 'b1750dc124e783ab52e640da8ba50c8629c8d2ec',
                'terminal_token' => 'f06b187300bcef6a58fd600c09739906f74c5cd7',
                'is_active' => false,
                'sort_order' => 5,
                'monthly_cap' => 300000,
            ],
            [
                'name' => 'Danieli Soft',
                'slug' => 'danieli-soft',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => '6ac866193163edd0e4cff6e7b7d0a55931f9eee0',
                'password' => 'fab845f788de76ce6ef23a01d86dabe9d1f85823',
                'terminal_token' => '34a89a6a837412347281eae8806942ba1148bf4a',
                'is_active' => false,
                'sort_order' => 6,
                'monthly_cap' => 300000,
            ],
            [
                'name' => 'Vinci Payments FR',
                'slug' => 'vinci-payments-fr',
                'endpoint' => 'gate.emerchantpay.net',
                'username' => 'deff5617dfa912f114bd5419902319dedff737e9',
                'password' => '7fa3a339b14c29450682415704b84bb1cec4f7c4',
                'terminal_token' => '496251c0424ec2c4094d183362f876d2e4c127a9',
                'is_active' => false,
                'sort_order' => 7,
                'monthly_cap' => 300000,
            ],
        ];
        foreach ($accounts as $account) {
            EmpAccount::updateOrCreate(
                ['slug' => $account['slug']],
                $account
            );
        }
    }
}
