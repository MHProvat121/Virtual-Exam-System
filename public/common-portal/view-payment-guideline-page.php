<?php
// view-payment-guideline-page.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Guidelines</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: #222222;
            background-color: #f4f6f9;
            padding: 30px 15px;
            line-height: 1.5;
        }

        .page-container {
            max-width: 920px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px;
            border-radius: 4px;
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.06);
        }

        /* Header Row with Back Button */
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
            flex-wrap: wrap;
            gap: 12px;
        }

        h1.main-title {
            color: #0b458c;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 0;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #003380;
            color: #ffffff;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            padding: 8px 16px;
            border-radius: 4px;
            transition: background-color 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-back:hover {
            background-color: #0b458c;
            box-shadow: 0 2px 6px rgba(0, 51, 128, 0.25);
        }

        h2.section-header {
            color: #0b458c;
            font-size: 20px;
            font-weight: bold;
            margin-top: 25px;
            margin-bottom: 15px;
        }

        h3.item-title {
            color: #0b458c;
            font-size: 15px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        p {
            font-size: 13px;
            color: #333333;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        /* Tables */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            margin-top: 12px;
            margin-bottom: 10px;
        }

        table.payment-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table.payment-table th {
            background-color: #003380;
            color: #ffffff;
            font-weight: bold;
            text-align: left;
            padding: 8px 12px;
            border: 1px solid #003380;
        }

        table.payment-table td {
            padding: 10px 12px;
            border: 1px solid #dce2e8;
            vertical-align: middle;
            color: #222222;
        }

        table.payment-table tbody tr:nth-child(odd) td {
            background-color: #f8fafc;
        }

        table.payment-table tbody tr:nth-child(even) td {
            background-color: #ffffff;
        }

        .logo-cell {
            width: 130px;
            text-align: center;
        }

        .logo-cell svg {
            max-width: 115px;
            max-height: 44px;
            display: block;
            margin: 0 auto;
        }

        .note-text {
            font-size: 12px;
            color: #444444;
            margin-top: 8px;
            margin-bottom: 16px;
            line-height: 1.5;
        }

        /* Sub-lists for Cards */
        .card-group-title {
            font-weight: bold;
            font-size: 13px;
            color: #222222;
            margin-top: 12px;
            margin-bottom: 6px;
        }

        .diamond-list {
            list-style: none;
            padding-left: 0;
            margin-top: 0;
            margin-bottom: 12px;
        }

        .diamond-list li {
            position: relative;
            padding-left: 18px;
            margin-bottom: 5px;
            font-size: 13px;
            color: #333333;
        }

        .diamond-list li::before {
            content: "◆";
            position: absolute;
            left: 0;
            top: 0px;
            color: #f2ad00;
            font-size: 10px;
        }

        /* Card Badges Container */
        .card-badges {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .badge-box-dbbl {
            border: 1px solid #c0c8d0;
            border-radius: 4px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            background-color: #ffffff;
        }

        .dbbl-cards-stack {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .card-pill {
            font-size: 10px;
            font-weight: bold;
            color: #ffffff;
            padding: 2px 8px;
            border-radius: 3px;
            text-align: center;
            text-transform: uppercase;
        }

        .pill-nexus { background-color: #003380; }
        .pill-master { background-color: #d9383a; }
        .pill-visa { background-color: #00805a; }

        .badge-box-any {
            border: 1px solid #c0c8d0;
            border-radius: 4px;
            padding: 8px 12px;
            background-color: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .badge-box-any .any-title {
            font-size: 10px;
            font-weight: bold;
            color: #003380;
            margin-bottom: 4px;
            align-self: flex-start;
        }

        /* Contact Section */
        .contact-section {
            margin-top: 30px;
        }

        .contact-section h2 {
            color: #0b458c;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .contact-info p {
            font-weight: bold;
            color: #222222;
            margin-bottom: 6px;
        }
    </style>
</head>
<body>

<div class="page-container">

    <!-- Payment Procedure Header & Back Button -->
    <div class="header-row">
        <h1 class="main-title">Payment Procedure</h1>
        <a href="../student-portal/student-enroll-page.php" class="btn-back">
            &larr; Back to Enrollment Page
        </a>
    </div>

    <p>
        All payments should be made through Mobile Banking (bKash, rocket, Nagad) /Debit or Credit Card (VISA, MASTER, NEXUS etc.)/designated Bank branches mentioned in the list. Cash payments will not be accepted by the Office of Finance and Accounts (F&amp;A). Students can pay with an auto-generated deposit slip printed from ERP. They can also collect printed deposit slips from the Office of Finance &amp; Accounts. A deposit slip will not be required in case of mobile banking or card payments.
    </p>

    <!-- Payment Systems -->
    <h2 class="section-header">Payment Systems</h2>

    <!-- 1. Mobile Financial Services (MFS) -->
    <h3 class="item-title">1. Mobile Financial Services (MFS):</h3>
    <p>
        You can pay your Tuition &amp; other Fees through Mobile Banking using Apps like Rocket, bKash, and Nagad and the standard charge is applicable by the service provider.
    </p>

    <div class="table-responsive">
        <table class="payment-table">
            <thead>
                <tr>
                    <th class="logo-cell">Logo</th>
                    <th style="width: 25%;">Pay Name</th>
                    <th style="width: 22%;">Biller No</th>
                    <th>Account Number</th>
                </tr>
            </thead>
            <tbody>
                <!-- Rocket -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 160 55" width="120" height="42">
                            <path d="M 125,5 L 140,40 L 100,28 Z" fill="#8C208C"/>
                            <path d="M 125,5 L 115,36 L 100,28 Z" fill="#6B186B"/>
                            <text x="5" y="22" font-family="Arial, sans-serif" font-size="14" font-weight="900" fill="#8C208C">ROCKET</text>
                            <text x="5" y="44" font-family="Arial, sans-serif" font-size="20" font-weight="bold" fill="#8C208C">রকেট</text>
                        </svg>
                    </td>
                    <td><strong>Rocket Bill Pay</strong></td>
                    <td><strong>Biller No. 2922</strong></td>
                    <td><strong>AC No. 10000000XXXX (No charge)</strong></td>
                </tr>
                <!-- bKash -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 160 55" width="120" height="42">
                            <polygon points="100,10 135,20 115,48 100,30" fill="#E2136E"/>
                            <polygon points="135,20 155,24 130,35" fill="#C10F5B"/>
                            <polygon points="100,10 115,48 85,38" fill="#E2136E"/>
                            <text x="10" y="38" font-family="Arial, sans-serif" font-size="26" font-weight="bold" fill="#222">b<tspan fill="#E2136E">Kash</tspan></text>
                        </svg>
                    </td>
                    <td><strong>bKash Pay Bill</strong></td>
                    <td></td>
                    <td><strong>AC No. 01872-XXXXXX (1.50% charge)</strong></td>
                </tr>
                <!-- Nagad -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 160 55" width="120" height="42">
                            <path d="M 20,38 C 10,25 25,10 38,18 C 48,8 60,20 50,38 Z" fill="#F7931E"/>
                            <path d="M 25,36 C 20,28 30,18 38,24 Z" fill="#ED1C24"/>
                            <text x="60" y="30" font-family="Arial, sans-serif" font-size="22" font-weight="bold" fill="#ED1C24">নগদ</text>
                            <text x="60" y="44" font-family="Arial, sans-serif" font-size="11" font-weight="bold" fill="#ED1C24">nagad</text>
                        </svg>
                    </td>
                    <td><strong>Nagad Bill Pay</strong></td>
                    <td><strong>Biller No. 1388</strong></td>
                    <td><strong>AC No. 01856-XXXXXX (1% charge)</strong></td>
                </tr>
                <!-- bKash Dynamic Charging -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 160 55" width="120" height="42">
                            <polygon points="100,10 135,20 115,48 100,30" fill="#E2136E"/>
                            <polygon points="135,20 155,24 130,35" fill="#C10F5B"/>
                            <polygon points="100,10 115,48 85,38" fill="#E2136E"/>
                            <text x="10" y="38" font-family="Arial, sans-serif" font-size="26" font-weight="bold" fill="#222">b<tspan fill="#E2136E">Kash</tspan></text>
                        </svg>
                    </td>
                    <td><strong>bKash Dynamic Charging</strong></td>
                    <td></td>
                    <td><strong>AC No. 01798-XXXXXX (1% processing fee or maximum 150 BDT)</strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="note-text">
        ** All the above mobile banking providers are integrated with Virtual Exam System account which will automatically update payments in the student's portal.
    </div>

    <!-- 2. Bank Payment -->
    <h3 class="item-title">2. Bank Payment:</h3>
    <p>
        You can pay your tuition &amp; other Fees through the below-mentioned banks in any branch within Bangladesh. The name of the banks and account details are:
    </p>

    <div class="table-responsive">
        <table class="payment-table">
            <thead>
                <tr>
                    <th class="logo-cell">Logo</th>
                    <th style="width: 45%;">Bank Name</th>
                    <th>Account Number</th>
                </tr>
            </thead>
            <tbody>
                <!-- Bank Asia -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 160 50" width="120" height="38">
                            <rect x="5" y="8" width="34" height="34" fill="none" stroke="#1B4E9B" stroke-width="4"/>
                            <line x1="22" y1="8" x2="22" y2="42" stroke="#1B4E9B" stroke-width="4"/>
                            <line x1="5" y1="25" x2="39" y2="25" stroke="#1B4E9B" stroke-width="4"/>
                            <text x="46" y="24" font-family="Arial, sans-serif" font-size="12" font-weight="bold" fill="#1B4E9B">ব্যাংক এশিয়া</text>
                            <text x="46" y="38" font-family="Arial, sans-serif" font-size="14" font-weight="bold" fill="#1B4E9B">Bank Asia</text>
                        </svg>
                    </td>
                    <td><strong>Bank Asia Limited**</strong></td>
                    <td><strong>0153300XXXX</strong></td>
                </tr>
                <!-- Dutch-Bangla Bank -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 170 50" width="125" height="38">
                            <ellipse cx="22" cy="22" rx="18" ry="12" fill="#d0d5dd"/>
                            <path d="M 12,22 Q 22,12 32,22" fill="none" stroke="#003380" stroke-width="3"/>
                            <path d="M 12,25 Q 22,15 32,25" fill="none" stroke="#d9383a" stroke-width="3"/>
                            <path d="M 12,28 Q 22,18 32,28" fill="none" stroke="#00805a" stroke-width="3"/>
                            <text x="45" y="24" font-family="Arial, sans-serif" font-size="12" font-weight="bold" fill="#000">Dutch-Bangla Bank</text>
                            <text x="45" y="36" font-family="Arial, sans-serif" font-size="8" fill="#444">YOUR TRUSTED PARTNER</text>
                        </svg>
                    </td>
                    <td><strong>Dutch-Bangla Bank PLC.**</strong></td>
                    <td><strong>117110000XXXX</strong></td>
                </tr>
                <!-- NCC Bank -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 160 50" width="120" height="38">
                            <path d="M 10,25 C 10,12 25,12 30,22 C 20,20 18,30 30,35 C 18,35 10,30 10,25 Z" fill="#0055a5"/>
                            <path d="M 35,12 C 45,12 48,22 40,28 C 45,20 35,18 35,12 Z" fill="#d9383a"/>
                            <text x="52" y="28" font-family="Arial, sans-serif" font-size="16" font-weight="bold" fill="#0055a5">NCC Bank</text>
                            <text x="52" y="39" font-family="Arial, sans-serif" font-size="8" fill="#333">With You. Always.</text>
                        </svg>
                    </td>
                    <td><strong>National Credit and Commerce Bank PLC.</strong></td>
                    <td><strong>0033021001XXXX</strong></td>
                </tr>
                <!-- Al-Arafah Islami Bank -->
                <tr>
                    <td class="logo-cell">
                        <svg viewBox="0 0 170 50" width="125" height="38">
                            <path d="M 10,25 Q 25,5 40,25 L 40,40 L 10,40 Z" fill="none" stroke="#468232" stroke-width="3"/>
                            <rect x="18" y="20" width="14" height="18" fill="#555555"/>
                            <polygon points="18,20 25,15 32,20" fill="#DAA520"/>
                            <text x="46" y="22" font-family="Arial, sans-serif" font-size="18" font-weight="bold" fill="#333">aibl</text>
                            <text x="46" y="32" font-family="Arial, sans-serif" font-size="8" font-weight="bold" fill="#333">Al-Arafah Islami Bank PLC.</text>
                        </svg>
                    </td>
                    <td><strong>Al-Arafah Islami Bank PLC.</strong></td>
                    <td><strong>017102000XXXX</strong></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="note-text">
        **Bank Asia Ltd and Dutch-Bangla Bank PLC are the two preferred banks for payment as these banks' systems are integrated with "Virtual Exam System account" and automatic payment updates in the student portal are available.<br><br>
        In case of other bank payments, you have to visit the Finance &amp; Accounts (F&amp;A) office with a deposit slip to update your payment in the student portal manually.
    </div>

    <!-- 3. Online Debit/Credit Card Payment -->
    <h3 class="item-title">3. Online Debit/Credit Card Payment:</h3>
    <p>
        You can pay tuition &amp; other Fees through an online payment gateway using credit/debit cards like (Nexus, Master &amp; VISA etc.) A standard charge is applicable by the service provider. The following types of cards are accepted for payment:
    </p>

    <div class="card-group-title">a. Dutch-Bangla Bank PLC.</div>
    <ul class="diamond-list">
        <li>Nexus Debit Card</li>
        <li>Master Debit Card</li>
        <li>VISA Debit Card</li>
    </ul>

    <div class="card-group-title">b. Any Bank Any Card (Debit or Credit):</div>
    <ul class="diamond-list">
        <li>MasterCard</li>
        <li>VISA Card</li>
    </ul>

    <!-- Card Logos Graphical Box -->
    <div class="card-badges">
        <!-- DBBL Cards Badge -->
        <div class="badge-box-dbbl">
            <svg viewBox="0 0 50 40" width="40" height="32">
                <ellipse cx="25" cy="18" rx="18" ry="11" fill="#d0d5dd"/>
                <path d="M 15,18 Q 25,10 35,18" fill="none" stroke="#003380" stroke-width="2.5"/>
                <path d="M 15,20 Q 25,12 35,20" fill="none" stroke="#d9383a" stroke-width="2.5"/>
                <path d="M 15,22 Q 25,14 35,22" fill="none" stroke="#00805a" stroke-width="2.5"/>
                <text x="25" y="35" font-family="Arial, sans-serif" font-size="8" font-weight="bold" text-anchor="middle" fill="#000">DBBL</text>
            </svg>
            <div class="dbbl-cards-stack">
                <div class="card-pill pill-nexus">NEXUS Debit</div>
                <div class="card-pill pill-master">MASTER Debit</div>
                <div class="card-pill pill-visa">VISA Debit</div>
            </div>
        </div>

        <!-- Any Bank VISA / MasterCard Badge -->
        <div class="badge-box-any">
            <div class="any-title">Any Bank</div>
            <svg viewBox="0 0 100 35" width="90" height="30">
                <!-- VISA logo -->
                <text x="2" y="18" font-family="Arial, sans-serif" font-size="16" font-weight="900" fill="#1A1F71" font-style="italic">VISA</text>
                <!-- Mastercard logo -->
                <circle cx="62" cy="14" r="9" fill="#EB001B"/>
                <circle cx="72" cy="14" r="9" fill="#F79E1B" fill-opacity="0.8"/>
                <text x="50" y="30" font-family="Arial, sans-serif" font-size="7" font-weight="bold" fill="#333">MasterCard</text>
                <text x="88" y="30" font-family="Arial, sans-serif" font-size="7" font-weight="bold" fill="#333">Card</text>
            </svg>
        </div>
    </div>

    <!-- 4. POS Machine -->
    <h3 class="item-title">4. POS Machine:</h3>
    <p>
        You can pay Tuition &amp; other Fees through POS Machine using debit/credit cards at the Office of Finance &amp; Accounts (standard charge is applicable by the service provider).
    </p>

    <!-- 5. Cash Payment -->
    <h3 class="item-title">5. Cash Payment:</h3>
    <p>
        The Office of Finance &amp; Accounts DOES NOT accept cash deposits for tuition/other fees. Only new students' admission fees are received in cash.
    </p>

    <!-- 6. Online Admission Fees -->
    <h3 class="item-title">6. Online Admission Fees:</h3>
    <p>
        Student may pay their admission fees through bKash Merchant A/C No. 01856-484521
    </p>

    <!-- 7. aamarPay -->
    <h3 class="item-title">7. aamarPay</h3>

    <!-- Contact -->
    <div class="contact-section">
        <h2>Contact</h2>
        <div class="contact-info">
            <p>For account related queries please contact: 018XXXXXXX, 018XXXXXXX &amp; 017XXXXXX</p>
            <p>(Office hours 09:00 am 05:00 pm, Except Saturday - Sunday)</p>
            <p>E-mail: accounts@ves.edu.bd</p>
        </div>
    </div>

</div>

</body>
</html>