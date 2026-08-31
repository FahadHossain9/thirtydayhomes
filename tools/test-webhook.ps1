<#
    Plays Stripe's part, so the webhook can be tested without Stripe.

        powershell -ExecutionPolicy Bypass -File tools\test-webhook.ps1
        powershell -ExecutionPolicy Bypass -File tools\test-webhook.ps1 -Cleanup

    WHY THIS EXISTS

    Stripe cannot reach http://localhost, so it can never deliver a real
    event to a machine at home. This signs payloads the same way Stripe does
    - HMAC-SHA256 over "<timestamp>.<raw body>" - and POSTs them to the same
    endpoint. To the plugin they are indistinguishable from the real thing,
    which is the point: the signature check is exactly what is being tested.

    It leaves a demo landlord signed up so the dashboard can be looked at
    between steps. Run it again with -Cleanup to remove everything.

    This file lives in the repository's tools/, NOT in the plugin, so it is
    never in a release zip and can never reach a live server. It can grant a
    membership; that must stay a local-only ability.
#>

param(
    # Run one step at a time so the dashboard can be looked at in between.
    # Without it every step runs at once, which proves the same thing but
    # never gives you a moment to go and see the change on screen.
    [int]$Step = 0,
    [switch]$Cleanup,
    [string]$SiteUrl  = 'http://localhost/thirtydayhomes',
    [string]$Php      = 'D:\xampp\php\php.exe',
    [string]$WpCli    = 'D:\xampp\wp-cli.phar',
    [string]$WpRoot   = 'D:\xampp\htdocs\thirtydayhomes'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http

$email    = 'webhook-demo@example.test'
$password = 'WebhookDemo12345'
$customer = 'cus_webhook_demo'
$secret   = 'whsec_local_demo_secret_do_not_use_in_production'
$endpoint = "$SiteUrl/wp-json/tdh/v1/stripe-webhook"
$tmp      = Join-Path $env:TEMP 'tdh-webhook-demo.php'

Set-Location $WpRoot

function Run-Php([string]$code) {
    # A here-string written as UTF-8 without a BOM: Set-Content -Encoding UTF8
    # writes one, and a BOM before <?php is output before headers.
    [System.IO.File]::WriteAllText($tmp, $code, (New-Object System.Text.UTF8Encoding($false)))
    return & $Php $WpCli eval-file $tmp --skip-plugins=elementor
}

function Read-Kv([string[]]$lines) {
    $h = @{}
    $lines | ForEach-Object { if ($_ -match '^(\w+)=(.*)$') { $h[$Matches[1]] = $Matches[2] } }
    return $h
}

# ---------------------------------------------------------------- cleanup ---

if ($Cleanup) {
    $out = Run-Php @"
<?php
use TDH\Billing\Stripe;
`$u = get_user_by( 'email', '$email' );
if ( `$u ) { wp_delete_user( `$u->ID ); echo "user removed\n"; } else { echo "no demo user\n"; }
Stripe::save_credential( Stripe::MODE_TEST, 'webhook', (string) get_option( 'tdh_demo_webhook_backup', '' ) );
delete_option( 'tdh_demo_webhook_backup' );
global `$wpdb;
`$wpdb->query( "DELETE FROM {`$wpdb->options} WHERE option_name LIKE '%tdh_stripe_event_%'" );
echo "webhook secret restored, event memory cleared\n";
"@
    $out
    Remove-Item $tmp -ErrorAction SilentlyContinue
    "`nDone. Nothing left behind."
    return
}

# ------------------------------------------------------------------ setup ---

'==============================================================='
'  STEP 1  -  set up a demo landlord'
'==============================================================='

$setup = Read-Kv (Run-Php @"
<?php
use TDH\Billing\Stripe;
use TDH\Membership;

// Remember the real webhook secret so cleanup can put it back.
if ( false === get_option( 'tdh_demo_webhook_backup', false ) ) {
    update_option( 'tdh_demo_webhook_backup', Stripe::credential( 'webhook', Stripe::MODE_TEST ) );
}
Stripe::save_credential( Stripe::MODE_TEST, 'webhook', '$secret' );

`$u = get_user_by( 'email', '$email' );
if ( ! `$u ) {
    `$id = wp_insert_user( [
        'user_login'   => 'webhook_demo',
        'user_email'   => '$email',
        'user_pass'    => '$password',
        'display_name' => 'Webhook Demo',
        'role'         => TDH\Roles::LANDLORD,
    ] );
} else {
    `$id = `$u->ID;
}

Membership::apply( (int) `$id, [ 'customer' => '$customer', 'status' => Membership::NONE, 'quota' => 0, 'expires' => 0, 'plan' => '' ] );

echo 'USER=' . `$id . "\n";
echo 'PRICE1=' . Stripe::price_id( 1, Stripe::MODE_TEST ) . "\n";
echo 'PRICE3=' . Stripe::price_id( 3, Stripe::MODE_TEST ) . "\n";
echo 'STATUS=' . Membership::status( (int) `$id ) . "\n";
echo 'QUOTA=' . Membership::quota( (int) `$id ) . "\n";
"@)

if (-not $setup.PRICE3) {
    ''
    '  !! No Price IDs are saved yet.'
    '     Go to Listings -> Payments, fill in the Test / Sandbox tab first.'
    return
}

"  landlord #$($setup.USER) created"
"  email    : $email"
"  password : $password"
""
"  membership now : $($setup.STATUS), quota $($setup.QUOTA)"
''
'  >> Open a private window and sign in at:'
"     $SiteUrl/login/"
'     You should see: No active plan, and Listings 0 / 0'

# ----------------------------------------------------------------- events ---

function Sign([string]$payload, [long]$ts) {
    $h = New-Object System.Security.Cryptography.HMACSHA256
    $h.Key = [Text.Encoding]::UTF8.GetBytes($secret)
    $bytes = $h.ComputeHash([Text.Encoding]::UTF8.GetBytes("$ts.$payload"))
    $h.Dispose()
    return 't=' + $ts + ',v1=' + (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Send-Event([string]$payload, [string]$signature) {
    $c = New-Object System.Net.Http.HttpClient
    $req = New-Object System.Net.Http.HttpRequestMessage('POST', $endpoint)
    $req.Content = New-Object System.Net.Http.StringContent($payload, [Text.Encoding]::UTF8, 'application/json')
    if ($signature) { $req.Headers.Add('Stripe-Signature', $signature) }
    $r = $c.SendAsync($req).Result
    $body = $r.Content.ReadAsStringAsync().Result
    $c.Dispose()
    return @{ code = [int]$r.StatusCode; body = $body }
}

function Subscription-Event([string]$type, [string]$status, [string]$price, [long]$ends, [bool]$cancelling = $false) {
    return @{
        id       = "evt_demo_$([DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds())"
        type     = $type
        livemode = $false
        data     = @{ object = @{
            id                   = 'sub_webhook_demo'
            customer             = $customer
            status               = $status
            current_period_end   = $ends
            cancel_at_period_end = $cancelling
            items = @{ data = @( @{ price = @{ id = $price } } ) }
        } }
    } | ConvertTo-Json -Depth 10 -Compress
}

function Membership-Now() {
    $s = Read-Kv (Run-Php @"
<?php
use TDH\Membership;
`$u = get_user_by( 'email', '$email' );
echo 'STATUS=' . Membership::status( `$u->ID ) . "\n";
echo 'QUOTA=' . Membership::quota( `$u->ID ) . "\n";
echo 'PLAN=' . Membership::plan( `$u->ID ) . "\n";
"@)
    return "$($s.STATUS), quota $($s.QUOTA)$(if ($s.PLAN) { ", plan: $($s.PLAN)" })"
}

$now  = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$ends = $now + 2592000

# $Step 0 means "run them all". Otherwise only the one asked for.
function Want([int]$n) { return ($Step -eq 0 -or $Step -eq $n) }

if ($Step -eq 1) {
    ''
    '  Sign in as the landlord above, then come back and run:'
    '    ... test-webhook.ps1 -Step 2'
    Remove-Item $tmp -ErrorAction SilentlyContinue
    return
}

if (Want 2) {
''
'==============================================================='
'  STEP 2  -  a forged request, the kind an attacker sends'
'==============================================================='
$evt = Subscription-Event 'customer.subscription.updated' 'active' $setup.PRICE3 $ends
$r = Send-Event $evt (Sign $evt $now).Replace('v1=', 'v1=deadbeef')
"  response       : $($r.code) $($r.body)"
"  membership now : $(Membership-Now)"
'  >> Expected 400, and NOTHING granted. Refresh the dashboard - still 0 / 0.'
}

if (Want 3) {
''
'==============================================================='
'  STEP 3  -  a genuine payment: the 3-listing plan'
'==============================================================='
$evt = Subscription-Event 'customer.subscription.updated' 'active' $setup.PRICE3 $ends
$r = Send-Event $evt (Sign $evt $now)
"  response       : $($r.code) $($r.body)"
"  membership now : $(Membership-Now)"
'  >> Refresh the dashboard. Active, and Listings 0 / 3.'
}

if (Want 4) {
''
'==============================================================='
'  STEP 4  -  Stripe delivers the same event twice'
'==============================================================='
$evt = Subscription-Event 'customer.subscription.updated' 'active' $setup.PRICE3 $ends
$sig = Sign $evt $now
Send-Event $evt $sig | Out-Null
$r = Send-Event $evt $sig
"  response       : $($r.code) $($r.body)"
"  membership now : $(Membership-Now)"
'  >> Ignored as a duplicate. The quota did not double.'
}

if (Want 5) {
''
'==============================================================='
'  STEP 5  -  their card fails'
'==============================================================='
$evt = Subscription-Event 'customer.subscription.updated' 'past_due' $setup.PRICE3 $ends
$r = Send-Event $evt (Sign $evt $now)
"  response       : $($r.code) $($r.body)"
"  membership now : $(Membership-Now)"
'  >> Payment failed. The allowance is kept, so paying restores the'
'     listings without republishing them.'
}

if (Want 6) {
''
'==============================================================='
'  STEP 6  -  they cancel'
'==============================================================='
$evt = Subscription-Event 'customer.subscription.deleted' 'canceled' $setup.PRICE3 $ends
$r = Send-Event $evt (Sign $evt $now)
"  response       : $($r.code) $($r.body)"
"  membership now : $(Membership-Now)"
'  >> Expired, allowance back to 0.'
}

Remove-Item $tmp -ErrorAction SilentlyContinue

''
'==============================================================='
"  Sign in : $email  /  $password"
if ($Step -gt 0 -and $Step -lt 6) {
    "  Next    : ... test-webhook.ps1 -Step $($Step + 1)"
}
''
'  When finished:'
'    powershell -ExecutionPolicy Bypass -File tools\test-webhook.ps1 -Cleanup'
'==============================================================='
