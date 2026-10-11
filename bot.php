<?php
ob_start();

// ========== CONFIG ==========
$botToken = "8665350032:AAH1B72WSKvxZsyHkVE9ODsGVqZzzM9pdtQ";
$website = "https://api.telegram.org/bot".$botToken;
$adminID = "@Prime_Devil_Seller01"; // Purchase notifications are sent to this Telegram chat ID.

// ========== FILES ==========
foreach(["balances.json","orders.json","temp.json","users.json","settings.json","categories.json","products.json"] as $f) {
    if(!file_exists($f)) file_put_contents($f, "{}");
}

// ========== DEFAULT SETTINGS ==========
$settings = json_decode(file_get_contents("settings.json"), true);
if(!isset($settings['proof_link'])) $settings['proof_link'] = "https://t.me/YourProofChannel";
if(!isset($settings['howto_link'])) $settings['howto_link'] = "https://t.me/YourHowToVideo";
if(!isset($settings['support_user'])) $settings['support_user'] = "@YourSupportUsername";
if(!isset($settings['api_key'])) $settings['api_key'] = "fam_0a03f62f9d7939d78e15ab113e7c542fb63385d8";
if(!isset($settings['fampay_upi'])) $settings['fampay_upi'] = "alexmahawar@fam";
if(!isset($settings['fampay_qr_url'])) $settings['fampay_qr_url'] = "https://famgateway.in/api/qr.php";
if(!isset($settings['fampay_verify_url'])) $settings['fampay_verify_url'] = "https://famgateway.in/api/verify-order.php"; 
if(!isset($settings['reseller_api_url'])) $settings['reseller_api_url'] = "https://bantibhaiya.to/api/reseller_v1.php";
if(!isset($settings['reseller_api_key'])) $settings['reseller_api_key'] = "YOUR_RESELLR_API_ADD";
if(!isset($settings['reseller_master_key'])) $settings['reseller_master_key'] = "a7f3e8b2c9d1f4a6b8c2d5e9f1a3b6c8";
if(!isset($settings['maintenance_mode'])) $settings['maintenance_mode'] = false;
file_put_contents("settings.json", json_encode($settings));

// ========== GET UPDATE ==========
$update = json_decode(file_get_contents("php://input"), true);

if(isset($update["callback_query"])){
    $chat_id = $update["callback_query"]["message"]["chat"]["id"];
    $message_id = $update["callback_query"]["message"]["message_id"];
    $first_name = $update["callback_query"]["from"]["first_name"] ?? "User";
    $username = $update["callback_query"]["from"]["username"] ?? "";
    $user_id = $update["callback_query"]["from"]["id"];
    $text = "";
    $data = $update["callback_query"]["data"] ?? "";
} else {
    $chat_id = $update["message"]["chat"]["id"] ?? 0;
    $message_id = $update["message"]["message_id"] ?? 0;
    $first_name = $update["message"]["chat"]["first_name"] ?? "User";
    $username = $update["message"]["chat"]["username"] ?? "";
    $user_id = $update["message"]["chat"]["id"] ?? 0;
    $text = $update["message"]["text"] ?? "";
    $data = "";
}

// ========== LOAD DATA ==========
$balances = json_decode(file_get_contents("balances.json"), true);
$users = json_decode(file_get_contents("users.json"), true);
$orders = json_decode(file_get_contents("orders.json"), true);
$products = json_decode(file_get_contents("products.json"), true);

if(!isset($balances[$user_id])) $balances[$user_id] = 0;
if(!isset($users[$user_id])){
    $users[$user_id] = [
        "name" => $first_name,
        "username" => $username,
        "join" => date("d M Y"),
        "join_ts" => time()
    ];
    file_put_contents("users.json", json_encode($users));
} else {
    if(!isset($users[$user_id]["join"]) || trim((string)$users[$user_id]["join"]) === ""){
        $users[$user_id]["join"] = date("d M Y");
    }
    if(!isset($users[$user_id]["join_ts"])){
        $users[$user_id]["join_ts"] = strtotime($users[$user_id]["join"]) ?: time();
    }
}

// ========== MAINTENANCE MODE GATE ==========
// Admin can always use the bot. Normal users are stopped here while
// maintenance is enabled, so old inline buttons cannot open store actions.
if(!($user_id == $adminID) && !empty($settings['maintenance_mode'])){
    if($data){
        answerCallback($update["callback_query"]["id"] ?? "");
    }
    $maintenanceMsg = "🛠️ <b>Bot Maintenance</b>\\n\\n" .
        "Bot abhi maintenance mode mein hai.\\n" .
        "Thodi der baad <b>/start</b> karke dobara try karein.\\n\\n" .
        "⏳ <i>Please wait for the update.</i>";
    if($message_id > 0 && $data){
        editMsg($chat_id, $message_id, $maintenanceMsg, btn([[ ["🔄 Check Again","maintenance_check"] ]]));
    } else {
        sendMessage($chat_id, $maintenanceMsg, btn([[ ["🔄 Check Again","maintenance_check"] ]]));
    }
    return;
}

// ========== /START ==========
if(strpos($text, "/start") === 0){
    $startArg = trim(substr($text, 6));
    if($startArg !== "" && strpos($startArg, "ref") === 0){
        $referrerId = substr($startArg, 3);
        if(ctype_digit($referrerId) && (string)$referrerId !== (string)$user_id){
            if(!isset($users[$user_id]["referrer_id"]) && isset($users[$referrerId])){
                $users[$user_id]["referrer_id"] = (string)$referrerId;
                $users[$user_id]["referral_rewarded"] = false;
                file_put_contents("users.json", json_encode($users, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    saveBalance($user_id, $balances[$user_id]);
    sendMainMenu($chat_id, $first_name, $balances[$user_id]);
}

// ========== /ADMIN ==========
else if($text == "/admin"){
    if($user_id != $adminID){ sendMessage($chat_id, "❌Only admin"); return; }
    sendAdminPanel($chat_id, 0);
}

// ========== BUTTON CLICKS ==========
else if($data){
    // ---- SHARE PAGE ----
    // First tap behaves like Lucky/other menu buttons:
    // open a dedicated Share page instead of immediately leaving the bot.
    if($data == "share_page"){
        answerCallback($update["callback_query"]["id"]);
        sendReferral($chat_id, $message_id, $user_id);
        return;
    }

    // ---- SHARE LINK ----
    // Second tap opens Telegram's native share chooser.
    if($data == "share_link"){
        $refLink = "https://t.me/" . getBotUsername() . "?start=ref" . $user_id;
        $shareText = "𝗚𝗔𝗟𝗨 𝗠𝗢𝗗𝗭 𝗦𝗧𝗢𝗥𝗘  — Join using my referral link";
        $shareUrl = "https://t.me/share/url?url=" . rawurlencode($refLink) . "&text=" . rawurlencode($shareText);

        // answerCallbackQuery accepts a URL; do it only once.
        answerCallback($update["callback_query"]["id"], $shareUrl);
        return;
    }

    if(strpos($data, "check_") !== 0){
        answerCallback($update["callback_query"]["id"]);
    }

    // ---- ADMIN MAINTENANCE TOGGLE ----
    if($data == "toggle_maintenance" && $user_id == $adminID){
        $settings = json_decode(file_get_contents("settings.json"), true);
        if(!is_array($settings)) $settings = [];
        $settings['maintenance_mode'] = empty($settings['maintenance_mode']);
        file_put_contents("settings.json", json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        sendAdminPanel($chat_id, $message_id);
        return;
    }

    // ---- MAINTENANCE CHECK ----
    if($data == "maintenance_check"){
        if(!empty($settings['maintenance_mode'])){
            editMsg($chat_id, $message_id,
                "🛠️ <b>Bot Maintenance</b>\\n\\nBot abhi maintenance mode mein hai.\\nPlease wait for the update.",
                btn([[ ["🔄 Check Again","maintenance_check"] ]]));
        } else {
            $name = $users[$user_id]['name'] ?? $first_name;
            $bal = $balances[$user_id] ?? 0;
            sendMainMenu($chat_id, $name, $bal, $message_id);
        }
        return;
    }

    // ---- USER BUTTONS ----
    if($data == "addbal") sendAddBalance($chat_id, $message_id, $user_id);
    else if($data == "profile") sendProfile($chat_id, $message_id, $user_id);
    else if($data == "orders") sendOrders($chat_id, $message_id, $user_id);
    else if($data == "shop") sendProducts($chat_id, $message_id);
    else if($data == "proof") sendProof($chat_id, $message_id);
    else if($data == "howto") sendHowTo($chat_id, $message_id);
    else if($data == "support") sendSupport($chat_id, $message_id);
    else if($data == "lucky") sendLucky($chat_id, $message_id);
    
    // ---- CATEGORY PRODUCTS ----
    else if(strpos($data, "cat_") === 0){
        $cid = str_replace("cat_","",$data);
        sendProducts($chat_id, $message_id, $cid);
    }
    
    // ---- BUY PRODUCT (SHOW PLANS) ----
    else if(strpos($data, "buy_") === 0){
        $pid = str_replace("buy_","",$data);
        sendProductPlans($chat_id, $message_id, $pid);
    }
    
    // ---- STOCK OUT PLAN CLICK ----
    else if(strpos($data, "planout_") === 0){
        $parts = explode("_", $data);
        showStockOutWait($chat_id, $message_id, $parts[1] ?? "", (int)($parts[2] ?? -1));
    }

    // ---- PRODUCT MAINTENANCE PLAN CLICK ----
    else if(strpos($data, "maintplan_") === 0){
        $parts = explode("_", $data);
        showMaintenanceWait($chat_id, $message_id, $parts[1] ?? "", (int)($parts[2] ?? -1));
    }

    // ---- BUY SPECIFIC PLAN ----
    else if(strpos($data, "plan_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        $productsGuard = loadProductsSafe();
        if(isset($productsGuard[$pid]['maintenance']) && !empty($productsGuard[$pid]['maintenance'])){
            editMsg($chat_id,$message_id,"🛠️ <b>WAIT FOR NEW UPDATE</b>

Ye product abhi maintenance mein hai. Purchase temporarily unavailable hai.",btn([["Back to Shop","backcat"]]));
            return;
        }
        if(isset($productsGuard[$pid]['plans'][$plan_index]['stock_out']) && !empty($productsGuard[$pid]['plans'][$plan_index]['stock_out'])){
            editMsg($chat_id,$message_id,"🚫 <b>WAIT FOR STOCK</b>

Ye Days/Hours plan abhi STOCK OUT hai.",btn([["Back to Shop","backcat"]]));
            return;
        }
        showPurchaseConfirm($chat_id, $message_id, $user_id, $pid, $plan_index);
    }

    // ---- CONFIRM PRODUCT CHECKOUT / PAY UPI SHORTFALL ----
    else if(strpos($data, "purchase_upi_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[2] ?? "";
        $plan_index = (int)($parts[3] ?? -1);
        startProductUPIPayment($chat_id, $message_id, $user_id, $pid, $plan_index);
    }

    else if(strpos($data, "purchase_wallet_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[2] ?? "";
        $plan_index = (int)($parts[3] ?? -1);
        buyPlan($chat_id, $message_id, $user_id, $pid, $plan_index);
    }
    
    // ============================================================
    // ================ ADMIN PANEL BUTTONS =======================
    // ============================================================
    
    // ---- ADD PRODUCT ----
    else if($data == "addprod" && $user_id == $adminID){
        saveTemp($user_id, "addprod_cat", "default");
        saveTemp($user_id, "waiting", "prod_name");
        editMsg($chat_id, $message_id, "📦 <b>Product Ka Naam Bhejo</b>\n\nExample: SILENT CHEATS SAFE", btn([["⬅️ Cancel","backadmin"]]));
    }
    
    // ---- 3. ADD PLAN ----
    else if($data == "addplan" && $user_id == $adminID){
        sendSelectProductForAddPlan($chat_id, $message_id);
    }
    
    else if(strpos($data, "addplanprod_") === 0){
        $pid = str_replace("addplanprod_","",$data);
        saveTemp($user_id, "addplan_pid", $pid);
        saveTemp($user_id, "waiting", "plan_duration");
        editMsg($chat_id, $message_id, "📅 <b>Plan ki real Duration bhejo</b>\n\nExample: <code>1 Days</code>, <code>7 Days</code>, <code>24 Hours</code>\n\nJo tum yahan add karoge, Shop me wahi exact Days/Hours dikhega.", btn([[ ["⬅️ Cancel","backadmin"] ]]));
    }
    
    // ---- 4. ADD PLAN KEYS ----
    else if($data == "addplankeys" && $user_id == $adminID){
        sendSelectProductForAddPlanKeys($chat_id, $message_id);
    }
    
    else if(strpos($data, "addkeysprod_") === 0){
        $pid = str_replace("addkeysprod_","",$data);
        sendPlansForAddKeys($chat_id, $message_id, $pid);
    }
    
    else if(strpos($data, "addkeysplan_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        saveTemp($user_id, "addkeys_pid", $pid);
        saveTemp($user_id, "addkeys_plan_index", $plan_index);
        saveTemp($user_id, "waiting", "add_plan_keys");
        editMsg($chat_id, $message_id, "🔑 <b>Is plan ke liye Keys Add Karo</b>\n\nEk line me ek key\n\nExample:\nKEY1-ABCD\nKEY2-EFGH\nKEY3-IJKL", btn([[["⬅️ Cancel","backadmin"]]]));
    }
    
    // ---- REMOVE KEY ----
    else if($data == "removekey" && $user_id == $adminID){ sendSelectProductForRemoveKey($chat_id, $message_id); }
    else if(strpos($data, "remkeyprod_") === 0 && $user_id == $adminID){ sendPlansForRemoveKey($chat_id, $message_id, substr($data, strlen("remkeyprod_"))); }
    else if(strpos($data, "remkeyplan_") === 0 && $user_id == $adminID){ $parts=explode("_",$data); sendKeysForRemove($chat_id,$message_id,$parts[1]??"",(int)($parts[2]??-1)); }
    else if(strpos($data, "remkey_") === 0 && $user_id == $adminID){ $parts=explode("_",$data,4); removeSinglePlanKey($chat_id,$message_id,$parts[1]??"",(int)($parts[2]??-1),(int)($parts[3]??-1)); }

    // ---- 5. EDIT PLAN ----
    else if($data == "editplan" && $user_id == $adminID){
        sendSelectProductForEditPlan($chat_id, $message_id);
    }
    
    else if(strpos($data, "editplanprod_") === 0){
        $pid = str_replace("editplanprod_","",$data);
        sendPlansForEdit($chat_id, $message_id, $pid);
    }
    
    else if(strpos($data, "editplan_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        saveTemp($user_id, "edit_pid", $pid);
        saveTemp($user_id, "edit_plan_index", $plan_index);
        $products = json_decode(file_get_contents("products.json"), true);
        $plan = $products[$pid]['plans'][$plan_index];
        $keys_count = isset($plan['keys']) ? count($plan['keys']) : 0;
        $durationText = getPlanDurationText($plan);
        $msg = "✏️ <b>Edit Plan</b>\n\nCurrent: {$durationText} - ₹{$plan['price']}\nKeys: $keys_count\n\nKya edit karna hai?";
        $users = json_decode(file_get_contents("users.json"), true);
    if(!is_array($users)) $users = [];

    $kb = [
            [["📝 Name","editplanname_{$pid}_{$plan_index}"]],
            [["📅 Duration","editplanduration_{$pid}_{$plan_index}"]],
            [["💰 Price","editplanprice_{$pid}_{$plan_index}"]],
            [["🔑 Add Keys","editplankeys_{$pid}_{$plan_index}"]],
            [["⬅️ Back","backadmin"]]
        ];
        editMsg($chat_id, $message_id, $msg, btn($kb));
    }
    
    else if(strpos($data, "editplanname_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        saveTemp($user_id, "edit_pid", $pid);
        saveTemp($user_id, "edit_plan_index", $plan_index);
        saveTemp($user_id, "waiting", "edit_plan_name");
        editMsg($chat_id, $message_id, "📝 <b>Real Plan Name Bhejo</b>\n\nExample: PRIME HOOK NON ROOT", btn([[ ["⬅️ Cancel","backadmin"] ]]));
    }
    
    else if(strpos($data, "editplanduration_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        saveTemp($user_id, "edit_pid", $pid);
        saveTemp($user_id, "edit_plan_index", $plan_index);
        saveTemp($user_id, "waiting", "edit_plan_duration");
        editMsg($chat_id, $message_id, "📅 <b>Nayi Duration Bhejo</b>\n\nExample: 7 Days ya 24 Hours", btn([[["⬅️ Cancel","backadmin"]]]));
    }
    
    else if(strpos($data, "editplanprice_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        saveTemp($user_id, "edit_pid", $pid);
        saveTemp($user_id, "edit_plan_index", $plan_index);
        saveTemp($user_id, "waiting", "edit_plan_price");
        editMsg($chat_id, $message_id, "💰 <b>Naya Price Bhejo</b>\n\nExample: 199", btn([[["⬅️ Cancel","backadmin"]]]));
    }
    
    else if(strpos($data, "editplankeys_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        saveTemp($user_id, "edit_pid", $pid);
        saveTemp($user_id, "edit_plan_index", $plan_index);
        saveTemp($user_id, "waiting", "edit_plan_keys");
        editMsg($chat_id, $message_id, "🔑 <b>Is plan ke liye Keys Add Karo</b>\n\nEk line me ek key\n\nExample:\nKEY1-ABCD\nKEY2-EFGH", btn([[["⬅️ Cancel","backadmin"]]]));
    }
    
    // ---- 6. DELETE PLAN ----
    else if($data == "delplan" && $user_id == $adminID){
        sendSelectProductForDeletePlan($chat_id, $message_id);
    }
    
    else if(strpos($data, "delplanprod_") === 0){
        $pid = str_replace("delplanprod_","",$data);
        sendPlansForDelete($chat_id, $message_id, $pid);
    }
    
    else if(strpos($data, "delplan_") === 0){
        $parts = explode("_", $data);
        $pid = $parts[1];
        $plan_index = (int)$parts[2];
        $products = json_decode(file_get_contents("products.json"), true);
        $plan = $products[$pid]['plans'][$plan_index];
        unset($products[$pid]['plans'][$plan_index]);
        $products[$pid]['plans'] = array_values($products[$pid]['plans']);
        file_put_contents("products.json", json_encode($products));
        $durationText = getPlanDurationText($plan);
        editMsg($chat_id, $message_id, "🗑️ <b>Plan Delete Ho Gaya:</b> {$durationText} - ₹{$plan['price']}", btn([[["⬅️ Back","backadmin"]]]));
    }
    
    // ---- 7. EDIT PRODUCT ----
    else if($data == "editprod" && $user_id == $adminID){
        sendSelectProductForEdit($chat_id, $message_id);
    }
    
    else if(strpos($data, "editprod_") === 0){
        $pid = str_replace("editprod_","",$data);
        saveTemp($user_id, "edit_pid", $pid);
        $products = json_decode(file_get_contents("products.json"), true);
        $p = $products[$pid];
        $apiPid = htmlspecialchars((string)($p['api_pid'] ?? 'Not set'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $msg = "✏️ <b>Edit Product</b>\n\nName: ".htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."\nPID: <code>{$apiPid}</code>\nPlans: ".count($p['plans']);
        $kb = [
            [["📝 Name","edit_name_$pid"]],
            [["🆔 Set API PID","edit_apipid_$pid"]],
            [["⬅️ Back","backadmin"]]
        ];
        editMsg($chat_id, $message_id, $msg, btn($kb));
    }
    
    else if(strpos($data, "edit_name_") === 0){ 
        $pid = str_replace("edit_name_","",$data); 
        saveTemp($user_id, "edit_pid", $pid); 
        saveTemp($user_id, "waiting", "edit_name"); 
        editMsg($chat_id, $message_id, "📝 <b>Naya Name Bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]])); 
    }
    
    else if(strpos($data, "edit_apipid_") === 0 && $user_id == $adminID){
        $editPid = str_replace("edit_apipid_","",$data);
        $products = json_decode(file_get_contents("products.json"), true);
        if(!isset($products[$editPid])){
            editMsg($chat_id,$message_id,"❌ Product nahi mila.",btn([[["⬅️ Back","backadmin"]]]));
            return;
        }
        saveTemp($user_id,"edit_pid",$editPid);
        saveTemp($user_id,"waiting","edit_api_pid");
        editMsg($chat_id,$message_id,"🆔 <b>Exact Reseller API Product PID bhejo</b>

Example: <code>ABC123</code>",btn([[["⬅️ Cancel","backadmin"]]]));
    }

    // ---- 8. DELETE PRODUCT (FIXED) ----
    else if($data == "delprod" && $user_id == $adminID){
        sendSelectProductForDelete($chat_id, $message_id);
    }
    
    else if(strpos($data, "delprod_") === 0){
        $pid = str_replace("delprod_","",$data);
        $products = json_decode(file_get_contents("products.json"), true);
        if(!isset($products[$pid])){
            editMsg($chat_id, $message_id, "❌ Product nahi mila!", btn([[["⬅️ Back","backadmin"]]]));
            return;
        }
        $p = $products[$pid];
        unset($products[$pid]);
        file_put_contents("products.json", json_encode($products));
        editMsg($chat_id, $message_id, "🗑️ <b>Product Delete Ho Gaya!</b>\n\nName: {$p['name']}\nCategory: {$p['cat']}\nPlans: ".count($p['plans']), btn([[["⬅️ Back to Admin","backadmin"]]]));
    }
    
    // ---- PRODUCT/PLAN STOCK OUT ----
    else if($data == "stockout_menu" && $user_id == $adminID){
        sendStockOutProducts($chat_id, $message_id);
    }
    else if(strpos($data, "stockprod_") === 0 && $user_id == $adminID){
        $pid = substr($data, strlen("stockprod_"));
        sendStockOutPlans($chat_id, $message_id, $pid);
    }
    else if(strpos($data, "stockplanon_") === 0 && $user_id == $adminID){
        $parts = explode("_", $data, 3);
        $pid = $parts[1] ?? "";
        $idx = (int)($parts[2] ?? -1);
        togglePlanStockOut($chat_id, $message_id, $pid, $idx);
    }
    else if(strpos($data, "stockplan_") === 0 && $user_id == $adminID){
        $parts = explode("_", $data, 3);
        $pid = $parts[1] ?? "";
        $idx = (int)($parts[2] ?? -1);
        togglePlanStockOut($chat_id, $message_id, $pid, $idx);
    }

    // ---- PRODUCT MAINTENANCE ----
    else if($data == "product_maintenance" && $user_id == $adminID){
        sendMaintenanceProducts($chat_id, $message_id);
    }
    else if(strpos($data, "maintprod_") === 0 && $user_id == $adminID){
        $pid = substr($data, strlen("maintprod_"));
        toggleProductMaintenance($chat_id, $message_id, $pid);
    }

    // ---- 9. BROADCAST ----
    else if($data == "broadcast" && $user_id == $adminID){
        saveTemp($user_id, "waiting", "broadcast_text");
        editMsg($chat_id, $message_id, "📢 <b>Broadcast Message</b>\n\nMessage bhejo (Text, Photo, Video, Voice, Document)\n\n<b>Note:</b> Photo/Video/Voice/Document ke saath caption bhi bhej sakte ho", btn([[["⬅️ Cancel","backadmin"]]]));
    }
    
    // ---- 10. USER LIST ----
    // ---- USER BALANCE CHECK ----
    else if($data == "userbalcheck" && $user_id == $adminID){
        $users = json_decode(file_get_contents("users.json"), true);
        $balances = json_decode(file_get_contents("balances.json"), true);
        if(!is_array($users)) $users = [];
        if(!is_array($balances)) $balances = [];

        $msg = "💰 <b>User Balance Check</b>\n\n";
        $msg .= "👥 <b>Total Users:</b> ".count($users)."\n\n";

        if(empty($users)){
            $msg .= "❌ No registered users found.";
        } else {
            $i = 1;
            foreach($users as $id => $u){
                $name = htmlspecialchars((string)($u['name'] ?? 'Unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $username = htmlspecialchars((string)($u['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $balance = (float)($balances[$id] ?? 0);

                $msg .= $i.". <b>".$name."</b>";
                if($username !== '') $msg .= " (@".$username.")";
                $msg .= "\n🆔 <code>".htmlspecialchars((string)$id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>";
                $msg .= "\n💰 Balance: <b>₹".number_format($balance, 2)."</b>\n\n";

                $i++;
                if($i > 50){
                    $remaining = count($users) - 50;
                    if($remaining > 0) $msg .= "… and ".$remaining." more users";
                    break;
                }
            }
        }

        editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","backadmin"]]]));
    }

    else if($data == "userlist" && $user_id == $adminID){
        $users = json_decode(file_get_contents("users.json"), true);
        $msg = "👥 <b>Total Users:</b> ".count($users)."\n\n";
        $i = 1;
        foreach($users as $id => $u){
            $msg .= "$i. {$u['name']}";
            if(!empty($u['username'])) $msg .= " (@{$u['username']})";
            $msg .= "\n   🆔 <code>$id</code>\n   📅 {$u['join']}\n\n";
            $i++;
            if($i > 20) { $msg .= "\n... aur ". (count($users)-20) ." users"; break; }
        }
        $kb = [[["⬅️ Back","backadmin"]]];
        editMsg($chat_id, $message_id, $msg, btn($kb));
    }

    else if($data == "userbalcheck" && $user_id == $adminID){
        $users = json_decode(file_get_contents("users.json"), true);
        $balances = json_decode(file_get_contents("balances.json"), true);
        if(!is_array($users)) $users = [];
        if(!is_array($balances)) $balances = [];

        $msg = "💰 <b>User Balance Check</b>\n\n";
        $msg .= "👥 <b>Total Users:</b> ".count($users)."\n\n";

        if(empty($users)){
            $msg .= "❌ Abhi koi user registered nahi hai.";
        } else {
            $i = 1;
            foreach($users as $id => $u){
                $name = htmlspecialchars((string)($u['name'] ?? 'Unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $username = htmlspecialchars((string)($u['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $balance = formatMoney((float)($balances[$id] ?? 0));

                $msg .= "$i. <b>{$name}</b>";
                if($username !== '') $msg .= " (@{$username})";
                $msg .= "\n   🆔 <code>".htmlspecialchars((string)$id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>";
                $msg .= "\n   💰 Balance: <b>₹{$balance}</b>\n\n";

                $i++;
                if($i > 50){
                    $remaining = max(0, count($users) - 50);
                    if($remaining > 0) $msg .= "… aur {$remaining} users";
                    break;
                }
            }
        }

        editMsg($chat_id, $message_id, $msg, btn([[["⬅️ Back","backadmin"]]]));
    }

    // ---- 12. ADD USER BALANCE ----
    else if($data == "adduserbal" && $user_id == $adminID){
        saveTemp($user_id, "waiting", "adduserbal_id");
        editMsg($chat_id, $message_id, "💰 <b>User Balance Add</b>\n\nKisi bhi registered user ka <b>Telegram User ID</b> ya <b>@username</b> bhejo.\n\nExample ID: <code>8154859186</code>\nExample Username: <code>@Galu_Modz_Owner</code>\n\n➡️ Iske baad amount enter karke balance add kar sakte ho.", btn([[["⬅️ Cancel","backadmin"]]]));
    }
    // ---- 12. HOWTO LINK ----
    else if($data == "sethowto" && $user_id == $adminID){ 
        saveTemp($user_id, "waiting", "howto"); 
        editMsg($chat_id, $message_id, "📖 <b>How To Use Link Bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]])); 
    }
    
    // ---- 14. SUPPORT USERNAME ----
    else if($data == "setsupport" && $user_id == $adminID){ 
        saveTemp($user_id, "waiting", "support"); 
        editMsg($chat_id, $message_id, "💬 <b>Support Username Bhejo</b>", btn([[["⬅️ Cancel","backadmin"]]])); 
    }
    
    // ---- 15. API KEY ----
    else if($data == "setapi" && $user_id == $adminID){ 
        saveTemp($user_id, "waiting", "api"); 
        editMsg($chat_id, $message_id, "🔑 <b>FamPay API Key Bhejo</b>\n\nExample: fam_0a03f62f9d7939d78e15ab113e7c542fb63385d8", btn([[["⬅️ Cancel","backadmin"]]])); 
    }
    
    // ---- 16. FAMPAY UPI ID ----
    else if($data == "setupi" && $user_id == $adminID){
        saveTemp($user_id, "waiting", "fampay_upi");
        $currentUpi = htmlspecialchars((string)($settings['fampay_upi'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        editMsg($chat_id, $message_id, "💳 <b>FamPay UPI ID Bhejo</b>\n\nCurrent: <code>{$currentUpi}</code>\n\nExample: <code>alexmahawar@fam</code>", btn([[["⬅️ Cancel","backadmin"]]]));
    }

    // ---- 17. FAMPAY QR API URL ----
    else if($data == "setqrurl" && $user_id == $adminID){
        saveTemp($user_id, "waiting", "fampay_qr_url");
        $current = htmlspecialchars((string)($settings['fampay_qr_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        editMsg($chat_id, $message_id, "🌐 <b>FamPay QR API URL Bhejo</b>\n\nCurrent: <code>{$current}</code>\n\nExample: <code>https://famgateway.in/api/qr.php</code>", btn([[["⬅️ Cancel","backadmin"]]]));
    }

    // ---- 18. FAMPAY VERIFY API URL ----
    else if($data == "setverifyurl" && $user_id == $adminID){
        saveTemp($user_id, "waiting", "fampay_verify_url");
        $current = htmlspecialchars((string)($settings['fampay_verify_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        editMsg($chat_id, $message_id, "🔎 <b>FamPay Verify API URL Bhejo</b>\n\nCurrent: <code>{$current}</code>\n\nExample: <code>https://famgateway.in/api/qr.php</code>", btn([[["⬅️ Cancel","backadmin"]]]));
    }

    // ---- 19. BACK TO ADMIN ----
    else if($data == "backadmin"){
        sendAdminPanel($chat_id, $message_id);
    }
    
    // ---- PAYMENT ----
    else if(strpos($data, "pay_") === 0){ $amount = str_replace("pay_","",$data); createFamPayOrder($chat_id, $message_id, $user_id, $amount); }
    else if($data == "custom"){ saveTemp($user_id, "amount", "0"); sendKeypad($chat_id, $message_id, $user_id, "0"); }
    else if(strpos($data, "key_") === 0){ handleKeypad($chat_id, $message_id, $user_id, str_replace("key_","",$data)); }
    else if(strpos($data, "confirm_") === 0){ $amount = str_replace("confirm_","",$data); createFamPayOrder($chat_id, $message_id, $user_id, $amount); }
    else if(strpos($data, "check_") === 0){ checkPayment($chat_id, $message_id, str_replace("check_","",$data)); }
    else if(strpos($data, "cancel_") === 0){ cancelOrder($chat_id, $message_id, str_replace("cancel_","",$data)); }
    else if(strpos($data, "again_") === 0){ repeatPayment($chat_id, $message_id, str_replace("again_","",$data)); }
    
    // ---- NAVIGATION ----
    else if($data == "back") sendMainMenu($chat_id, $first_name, $balances[$user_id], $message_id);
    else if($data == "backkey"){ sendMainMenu($chat_id, $first_name, $balances[$user_id] ?? 0, $message_id); return; }
    else if($data == "backcat") sendProducts($chat_id, $message_id);
    else if($data == "backshop") sendProducts($chat_id, $message_id);
}

// ========== TEXT MESSAGES ==========
else if($text && $text != "/start" && $text != "/admin"){
    $temp = json_decode(file_get_contents("temp.json"), true);
    $waiting = $temp[$user_id]['waiting'] ?? "";
    $settings = json_decode(file_get_contents("settings.json"), true);
    $products = json_decode(file_get_contents("products.json"), true);

    // ---- ADD PRODUCT NAME ----
    if($waiting == "prod_name" && $user_id == $adminID){
        $cid = "default";
        $pname = trim($text);
        if($pname === '' || mb_strlen($pname) > 80){
            sendMessage($chat_id, "❌ Product name 1–80 characters ka hona chahiye.");
            return;
        }
        $newPid = "p".time().rand(10,99);
        $temp[$user_id]['pending_product_name'] = $pname;
        $temp[$user_id]['pending_product_id'] = $newPid;
        $temp[$user_id]['waiting'] = "prod_api_pid";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));
        sendMessage($chat_id, "🆔 <b>Reseller API Product PID bhejo</b>

PRODUCT REFERENCE TABLE se exact PID copy karo.

Example: <code>ABC123</code>");
    }

    // ---- ADD PRODUCT API PID ----
    else if($waiting == "prod_api_pid" && $user_id == $adminID){
        $apiPid = trim($text);
        $pname = trim((string)($temp[$user_id]['pending_product_name'] ?? ''));
        $newPid = trim((string)($temp[$user_id]['pending_product_id'] ?? ''));
        if($apiPid === '' || mb_strlen($apiPid) > 100 || preg_match('/\s/', $apiPid)){
            sendMessage($chat_id, "❌ Invalid PID. Exact PID bhejo, spaces ke bina.");
            return;
        }
        if($newPid === '' || $pname === ''){
            sendMessage($chat_id, "❌ Product session expire ho gaya. Product dobara add karo.");
            $temp[$user_id]['waiting'] = '';
            file_put_contents("temp.json", json_encode($temp));
            return;
        }
        // Save the basic product data first, then ask for the product's Update Files link.
        $products[$newPid] = [
            "name" => $pname,
            "cat" => "default",
            "api_pid" => $apiPid,
            "plans" => [],
            "update_link" => ""
        ];
        file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE), LOCK_EX);
        $temp[$user_id]['waiting'] = "prod_update_link";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));
        sendMessage($chat_id, "🔗 <b>Download Link Bhejo</b>

Is product ke liye direct Download Link bhejo.
Example: <code>https://example.com/download</code>

⚠️ Download link dena zaroori hai.");
    }

    // ---- ADD PRODUCT UPDATE FILES LINK ----
    else if($waiting == "prod_update_link" && $user_id == $adminID){
        $newPid = trim((string)($temp[$user_id]['pending_product_id'] ?? ''));
        $pname = trim((string)($temp[$user_id]['pending_product_name'] ?? ''));
        $link = trim($text);

        if($newPid === '' || !isset($products[$newPid])){
            sendMessage($chat_id, "❌ Product session expire ho gaya. Product dobara add karo.");
            $temp[$user_id]['waiting'] = "";
            file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));
            return;
        }

        if($link === '' || !filter_var($link, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $link)){
            sendMessage($chat_id, "❌ Valid http:// ya https:// Download Link bhejna zaroori hai.");
            return;
        }

        $products[$newPid]['update_link'] = $link;
        file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE), LOCK_EX);

        // After the download link, ask for the Telegram Premium Custom Emoji ID
        // that will be shown on this product button in Shop Store Product.
        $temp[$user_id]['waiting'] = "prod_emoji_id";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));
        sendMessage($chat_id, "✨ <b>Premium Emoji ID Bhejo</b>

Is product ke Shop button par jo Premium Custom Emoji dikhana hai uska ID bhejo.

Example: <code>6312217790304493294</code>

⚠️ Sirf Telegram Custom Emoji ID bhejo.");
        

    }

    // ---- ADD PRODUCT PREMIUM EMOJI ID ----
    else if($waiting == "prod_emoji_id" && $user_id == $adminID){
        $newPid = trim((string)($temp[$user_id]['pending_product_id'] ?? ''));
        $pname = trim((string)($temp[$user_id]['pending_product_name'] ?? ''));
        $emojiId = trim($text);

        if($newPid === '' || !isset($products[$newPid])){
            sendMessage($chat_id, "❌ Product session expire ho gaya. Product dobara add karo.");
            $temp[$user_id]['waiting'] = "";
            file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));
            return;
        }

        // Keep Telegram Custom Emoji IDs as strings so large IDs are never damaged.
        if($emojiId === '' || !preg_match('/^\d{10,30}$/', $emojiId)){
            sendMessage($chat_id, "❌ Valid Premium Custom Emoji ID bhejo. Example: <code>6312217790304493294</code>");
            return;
        }

        $products[$newPid]['custom_emoji_id'] = $emojiId;
        file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE), LOCK_EX);

        $temp[$user_id]['waiting'] = "";
        $temp[$user_id]['pending_product_name'] = "";
        $temp[$user_id]['pending_product_id'] = "";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));

        $safeName = htmlspecialchars($pname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeApiPid = htmlspecialchars((string)($products[$newPid]['api_pid'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeLink = htmlspecialchars((string)($products[$newPid]['update_link'] ?? ''), ENT_QUOTES, 'UTF-8');

        sendMessage($chat_id, "✨ <b>Product Add ho gaya!</b>

📦 Name: {$safeName}
🆔 PID: <code>{$safeApiPid}</code>
🔗 Download Link: <a href=\"{$safeLink}\">Open Link</a>
✨ Premium Emoji ID: <code>{$emojiId}</code>

Ab /admin se ➕ Add Plan karke real Duration + Price add karo.");
        sendAdminPanel($chat_id, 0);
    }

    // ---- ADD PLAN DURATION ----
    else if($waiting == "plan_duration" && $user_id == $adminID){
        $durationRaw = trim($text);
        $duration = getPlanDurationFields($durationRaw);
        if($duration === null || $duration['value'] <= 0){
            sendMessage($chat_id, "❌ Valid Duration bhejo.\n\nExample: 1 Days, 7 Days, 24 Hours");
            return;
        }
        // Keep the exact duration text entered by Admin.
        // The bot must never replace it with an automatic Days/Hours label.
        $temp[$user_id]['plan_duration_label'] = $durationRaw;
        $temp[$user_id]['plan_duration_value'] = $duration['value'];
        $temp[$user_id]['plan_duration_unit'] = $duration['unit'];
        $temp[$user_id]['waiting'] = "plan_price";
        file_put_contents("temp.json", json_encode($temp));
        sendMessage($chat_id, "💰 <b>Is plan ka real Price bhejo</b>\n\nExample: 50");
    }

    // ---- ADD PLAN PRICE ----
    else if($waiting == "plan_price" && $user_id == $adminID){
        $pid = $temp[$user_id]['addplan_pid'] ?? '';
        $durationValue = (float)($temp[$user_id]['plan_duration_value'] ?? 0);
        $durationUnit = strtolower((string)($temp[$user_id]['plan_duration_unit'] ?? 'days'));
        $priceText = trim($text);
        if($pid === '' || $durationValue <= 0 || !in_array($durationUnit, ['days','hours'], true) || $priceText === '' || !preg_match('/^\d+(?:\.\d+)?$/', $priceText)){
            sendMessage($chat_id, "❌ Valid Days/Price nahi mila. Plan dobara add karo.");
            return;
        }
        $price = (float)$priceText;
        $products = json_decode(file_get_contents("products.json"), true);
        if(!isset($products[$pid])){
            sendMessage($chat_id, "❌ Product nahi mila. Plan dobara add karo.");
            return;
        }
        if(!isset($products[$pid]['plans']) || !is_array($products[$pid]['plans'])){
            $products[$pid]['plans'] = [];
        }
        // Store exactly the real Duration and Price entered by Admin.
        $products[$pid]['plans'][] = [
            "duration_label" => (string)($temp[$user_id]["plan_duration_label"] ?? $durationValue . " " . $durationUnit),
            "duration_value" => $durationValue,
            "duration_unit" => $durationUnit,
            "days" => $durationUnit === "days" ? (int)$durationValue : 0,
            "hours" => $durationUnit === "hours" ? (int)$durationValue : 0,
            "price" => $price,
            "keys" => []
        ];
        file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE));
        $temp[$user_id]['waiting'] = "";
        $temp[$user_id]['plan_days'] = "";
        $temp[$user_id]['plan_duration_value'] = "";
        $temp[$user_id]['plan_duration_unit'] = "";
        $temp[$user_id]['plan_duration_label'] = "";
        $temp[$user_id]['addplan_pid'] = "";
        $temp[$user_id]['plan_name'] = "";
        file_put_contents("temp.json", json_encode($temp));
        $priceDisplay = rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
        $durationDisplay = getPlanDurationText($products[$pid]['plans'][count($products[$pid]['plans'])-1]);
        sendMessage($chat_id, "✅ <b>Plan Add Ho Gaya!</b>\n\nDuration: {$durationDisplay}\nPrice: ₹{$priceDisplay}\n\nAb is plan ke liye keys add karo!");
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- ADD PLAN KEYS ----
    else if($waiting == "add_plan_keys" && $user_id == $adminID){
        $keys = array_filter(array_map('trim', explode("\n", $text)));
        if(count($keys) == 0){
            sendMessage($chat_id, "❌ Koi key nahi mili. Dubara keys bhejo");
            return;
        }
        
        $pid = $temp[$user_id]['addkeys_pid'];
        $plan_index = $temp[$user_id]['addkeys_plan_index'];
        
        $products = json_decode(file_get_contents("products.json"), true);
        
        if(!isset($products[$pid]['plans'][$plan_index]['keys'])) {
            $products[$pid]['plans'][$plan_index]['keys'] = [];
        }
        $products[$pid]['plans'][$plan_index]['keys'] = array_merge(
            $products[$pid]['plans'][$plan_index]['keys'], 
            $keys
        );
        file_put_contents("products.json", json_encode($products));
        
        $temp[$user_id]['waiting'] = "";
        $temp[$user_id]['addkeys_pid'] = "";
        $temp[$user_id]['addkeys_plan_index'] = "";
        file_put_contents("temp.json", json_encode($temp));
        
        $plan = $products[$pid]['plans'][$plan_index];
        sendMessage($chat_id, "✅ ".count($keys)." Keys Add Ho Gayi!\n\nPlan: {$plan['days']} Days\nTotal Keys: ".count($plan['keys']));
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- EDIT NAME ----
    else if($waiting == "edit_name" && $user_id == $adminID){
        $pid = $temp[$user_id]['edit_pid'];
        $products[$pid]['name'] = $text;
        file_put_contents("products.json", json_encode($products));
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        sendMessage($chat_id, "✅ Name Update: $text");
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- EDIT PLAN NAME ----
    else if($waiting == "edit_plan_name" && $user_id == $adminID){
        $pid = $temp[$user_id]['edit_pid'];
        $plan_index = $temp[$user_id]['edit_plan_index'];
        $planName = trim($text);
        if($planName === ""){
            sendMessage($chat_id, "❌ Plan name empty nahi ho sakta. Real plan name bhejo.");
            return;
        }
        $products = json_decode(file_get_contents("products.json"), true);
        if(isset($products[$pid]['plans'][$plan_index])){
            $products[$pid]['plans'][$plan_index]['name'] = $planName;
            file_put_contents("products.json", json_encode($products));
        }
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        sendMessage($chat_id, "✅ Plan Name Update: $planName");
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- EDIT PLAN DURATION ----
    else if($waiting == "edit_plan_duration" && $user_id == $adminID){
        $pid = $temp[$user_id]['edit_pid'];
        $plan_index = $temp[$user_id]['edit_plan_index'];
        $durationRaw = trim($text);
        $duration = getPlanDurationFields($durationRaw);
        if($duration === null || $duration['value'] <= 0){
            sendMessage($chat_id, "❌ Valid Duration bhejo. Example: 7 Days ya 24 Hours");
            return;
        }
        $products = json_decode(file_get_contents("products.json"), true);
        if(isset($products[$pid]['plans'][$plan_index])){
            $products[$pid]['plans'][$plan_index]['duration_label'] = $durationRaw;
            $products[$pid]['plans'][$plan_index]['duration_value'] = $duration['value'];
            $products[$pid]['plans'][$plan_index]['duration_unit'] = $duration['unit'];
            $products[$pid]['plans'][$plan_index]['days'] = $duration['unit'] === 'days' ? (int)$duration['value'] : 0;
            $products[$pid]['plans'][$plan_index]['hours'] = $duration['unit'] === 'hours' ? (int)$duration['value'] : 0;
            file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE));
        }
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        $durationDisplay = getPlanDurationText($products[$pid]['plans'][$plan_index]);
        sendMessage($chat_id, "✅ Duration Update: {$durationDisplay}");
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- EDIT PLAN PRICE ----
    else if($waiting == "edit_plan_price" && $user_id == $adminID){
        $pid = $temp[$user_id]['edit_pid'];
        $plan_index = $temp[$user_id]['edit_plan_index'];
        $products = json_decode(file_get_contents("products.json"), true);
        $products[$pid]['plans'][$plan_index]['price'] = (int)$text;
        file_put_contents("products.json", json_encode($products));
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        sendMessage($chat_id, "✅ Price Update: ₹$text");
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- EDIT PLAN KEYS ----
    else if($waiting == "edit_plan_keys" && $user_id == $adminID){
        $keys = array_filter(array_map('trim', explode("\n", $text)));
        if(count($keys) == 0){
            sendMessage($chat_id, "❌ Koi key nahi mili. Dubara keys bhejo");
            return;
        }
        
        $pid = $temp[$user_id]['edit_pid'];
        $plan_index = $temp[$user_id]['edit_plan_index'];
        
        $products = json_decode(file_get_contents("products.json"), true);
        
        if(!isset($products[$pid]['plans'][$plan_index]['keys'])) {
            $products[$pid]['plans'][$plan_index]['keys'] = [];
        }
        $products[$pid]['plans'][$plan_index]['keys'] = array_merge(
            $products[$pid]['plans'][$plan_index]['keys'], 
            $keys
        );
        file_put_contents("products.json", json_encode($products));
        
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        
        $plan = $products[$pid]['plans'][$plan_index];
        sendMessage($chat_id, "✅ ".count($keys)." Keys Add Ho Gayi!\n\nPlan: {$plan['days']} Days\nTotal Keys: ".count($plan['keys']));
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- BROADCAST TEXT ----
    else if($waiting == "broadcast_text" && $user_id == $adminID){
        $users = json_decode(file_get_contents("users.json"), true);
        $sent = 0;
        $failed = 0;
        
        foreach($users as $uid => $u){
            $result = sendMessage($uid, "📢 <b>Announcement</b>\n\n$text");
            if($result !== false) $sent++; else $failed++;
            usleep(50000);
        }
        
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        
        sendMessage($chat_id, "✅ <b>Broadcast Complete!</b>\n\nTotal Users: ".count($users)."\n✅ Sent: $sent\n❌ Failed: $failed");
        sendAdminPanel($chat_id, 0);
    }
    
    // ---- USER BALANCE ADD - USER ID / USERNAME ----
    else if($waiting == "adduserbal_id" && $user_id == $adminID){
        $lookup = trim($text);
        $lookupClean = ltrim($lookup, "@");
        $users = json_decode(file_get_contents("users.json"), true);
        if(!is_array($users)) $users = [];

        $target_id = "";
        foreach($users as $id => $u){
            $uid = (string)$id;
            $uname = ltrim((string)($u['username'] ?? ''), "@");
            if($uid === $lookupClean || ($uname !== "" && strcasecmp($uname, $lookupClean) === 0)){
                $target_id = $uid;
                break;
            }
        }

        if($target_id === ""){
            sendMessage($chat_id, "❌ User nahi mila!\\n\\nRegistered user ka Telegram ID ya @username bhejo.", btn([[["⬅️ Cancel","backadmin"]]]));
            return;
        }

        $temp[$user_id]['adduserbal_target'] = $target_id;
        $temp[$user_id]['waiting'] = "adduserbal_amount";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $name = htmlspecialchars((string)($users[$target_id]['name'] ?? 'User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $username = !empty($users[$target_id]['username']) ? " (@".htmlspecialchars((string)$users[$target_id]['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').")" : "";
        $currentBalances = json_decode(file_get_contents("balances.json"), true);
        if(!is_array($currentBalances)) $currentBalances = [];
        $current = (float)($currentBalances[$target_id] ?? 0);

        sendMessage($chat_id, "💰 <b>User Balance Add</b>\\n\\n👤 User: <b>{$name}</b>{$username}\\n🆔 <code>{$target_id}</code>\\n💳 Current Balance: <b>₹".number_format($current, 2)."</b>\\n\\nKitna balance add karna hai?\\nExample: <code>100</code> ya <code>100.50</code>", btn([[["⬅️ Cancel","backadmin"]]]));
    }

    // ---- USER BALANCE ADD - AMOUNT ----
    else if($waiting == "adduserbal_amount" && $user_id == $adminID){
        $target_id = (string)($temp[$user_id]['adduserbal_target'] ?? "");
        $users = json_decode(file_get_contents("users.json"), true);
        if(!is_array($users) || !isset($users[$target_id])){
            $temp[$user_id]['waiting'] = "";
            $temp[$user_id]['adduserbal_target'] = "";
            file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            sendMessage($chat_id, "❌ User ab registered nahi hai. Dobara User Balance Add karo.", btn([[["⬅️ Back","backadmin"]]]));
            return;
        }

        $amountText = trim(str_replace([",", "₹", " "], "", $text));
        if($amountText === "" || !preg_match('/^(?:\d+)(?:\.\d{1,2})?$/', $amountText)){
            sendMessage($chat_id, "❌ Valid amount bhejo.\\n\\nExample: <code>100</code> ya <code>100.50</code>");
            return;
        }

        $amount = (float)$amountText;
        if($amount <= 0 || $amount > 100000000){
            sendMessage($chat_id, "❌ Amount ₹0 se zyada aur ₹10,00,00,000 se kam hona chahiye.");
            return;
        }

        $balances = json_decode(file_get_contents("balances.json"), true);
        if(!is_array($balances)) $balances = [];

        $old_bal = (float)($balances[$target_id] ?? 0);
        $new_bal = $old_bal + $amount;
        $balances[$target_id] = $new_bal;
        file_put_contents("balances.json", json_encode($balances, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $name = htmlspecialchars((string)($users[$target_id]['name'] ?? 'User'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amountFmt = number_format($amount, 2);
        $newFmt = number_format($new_bal, 2);

        $temp[$user_id]['waiting'] = "";
        $temp[$user_id]['adduserbal_target'] = "";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        sendMessage($chat_id, "✅ <b>User Balance Added Successfully</b>\\n\\n👤 User: <b>{$name}</b>\\n🆔 <code>{$target_id}</code>\\n➕ Added: <b>₹{$amountFmt}</b>\\n💰 New Balance: <b>₹{$newFmt}</b>");

        sendMessage($target_id, "💰 <b>Balance Added</b>\\n\\nAapke account me admin ne <b>₹{$amountFmt}</b> add kiya hai.\\n💳 <b>New Balance: ₹{$newFmt}</b>");

        sendAdminPanel($chat_id, 0);
    }
    // ---- SETTINGS ----
    else if($waiting == "howto" && $user_id == $adminID){
        $newHowtoLink = normalizeWebLink($text, "");
        if($newHowtoLink === ""){
            sendMessage($chat_id, "❌ Invalid How To Use link. Please send a full https:// or http:// link.");
            return;
        }

        $settings['howto_link'] = $newHowtoLink;
        file_put_contents("settings.json", json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        sendMessage($chat_id, "✅ How To Use Direct Link Updated:\n<code>".htmlspecialchars($newHowtoLink, ENT_QUOTES, 'UTF-8')."</code>");
        sendAdminPanel($chat_id, 0);
    }
    else if($waiting == "support" && $user_id == $adminID){ 
        $settings['support_user'] = $text; 
        file_put_contents("settings.json", json_encode($settings)); 
        $temp[$user_id]['waiting'] = ""; 
        file_put_contents("temp.json", json_encode($temp)); 
        sendMessage($chat_id, "✅ Support Username Update: $text"); 
        sendAdminPanel($chat_id, 0); 
    }
    else if($waiting == "fampay_upi" && $user_id == $adminID){
        $upi = trim($text);
        if($upi === "" || !preg_match('/^[^\\s@]+@[A-Za-z0-9._-]+$/', $upi)){
            sendMessage($chat_id, "❌ Valid UPI ID bhejo. Example: <code>alexmahawar@fam</code>");
            return;
        }
        $settings['fampay_upi'] = $upi;
        file_put_contents("settings.json", json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE), LOCK_EX);
        sendMessage($chat_id, "✅ FamPay UPI ID Update: <code>".htmlspecialchars($upi, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>");
        sendAdminPanel($chat_id, 0);
    }
    else if($waiting == "fampay_qr_url" && $user_id == $adminID){
        $url = trim($text);
        if(!filter_var($url, FILTER_VALIDATE_URL)){
            sendMessage($chat_id, "❌ Valid QR API URL bhejo.");
            return;
        }
        $settings['fampay_qr_url'] = $url;
        file_put_contents("settings.json", json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE), LOCK_EX);
        sendMessage($chat_id, "✅ FamPay QR API URL update ho gaya.");
        sendAdminPanel($chat_id, 0);
    }
    else if($waiting == "fampay_verify_url" && $user_id == $adminID){
        $url = trim($text);
        if(!filter_var($url, FILTER_VALIDATE_URL)){
            sendMessage($chat_id, "❌ Valid Verify API URL bhejo.");
            return;
        }
        $settings['fampay_verify_url'] = $url;
        file_put_contents("settings.json", json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE), LOCK_EX);
        sendMessage($chat_id, "✅ FamPay Verify API URL update ho gaya.");
        sendAdminPanel($chat_id, 0);
    }
    else if($waiting == "api" && $user_id == $adminID){ 
        $settings['api_key'] = trim($text); 
        file_put_contents("settings.json", json_encode($settings)); 
        $temp[$user_id]['waiting'] = ""; 
        file_put_contents("temp.json", json_encode($temp)); 
        sendMessage($chat_id, "✅ API Key Update: ".substr($text,0,20)."..."); 
        sendAdminPanel($chat_id, 0); 
    }
    
    // ---- EDIT PRODUCT API PID ----
    else if($waiting == "edit_api_pid" && $user_id == $adminID){
        $editPid = $temp[$user_id]['edit_pid'] ?? '';
        $apiPid = trim($text);

        if($editPid === '' || !isset($products[$editPid])){
            sendMessage($chat_id, "❌ Product nahi mila. Edit dobara karo.");
            return;
        }
        if($apiPid === '' || mb_strlen($apiPid) > 100 || preg_match('/\\s/', $apiPid)){
            sendMessage($chat_id, "❌ Invalid PID. Exact PID bhejo, spaces ke bina.");
            return;
        }

        $products[$editPid]['api_pid'] = $apiPid;
        file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE), LOCK_EX);
        $temp[$user_id]['waiting'] = '';
        file_put_contents("temp.json", json_encode($temp, JSON_UNESCAPED_UNICODE));

        sendMessage(
            $chat_id,
            "✅ API PID update ho gaya:\n\n🆔 <code>" .
            htmlspecialchars($apiPid, ENT_QUOTES, 'UTF-8') .
            "</code>"
        );
        sendAdminPanel($chat_id, 0);
    }

    // ---- CUSTOM AMOUNT ----
    else if(isset($temp[$user_id]['amount'])){
        $amount = (int)$text;
        if($amount >= 1 && $amount <= 5000){
            createFamPayOrder($chat_id, 0, $user_id, $amount);
            $temp[$user_id]['amount'] = null;
            file_put_contents("temp.json", json_encode($temp));
        }else{
            sendMessage($chat_id, "❌ Amount ₹1 se ₹5000 ke beech me hona chahiye");
        }
    }
}

// ========== HANDLE MEDIA FOR BROADCAST ==========
else if(isset($update["message"]) && $user_id == $adminID){
    $temp = json_decode(file_get_contents("temp.json"), true);
    $waiting = $temp[$user_id]['waiting'] ?? "";
    
    if($waiting == "broadcast_text"){
        $caption = $update["message"]["caption"] ?? "";
        $users = json_decode(file_get_contents("users.json"), true);
        $sent = 0;
        $failed = 0;
        
        if(isset($update["message"]["photo"])){
            $file_id = $update["message"]["photo"][count($update["message"]["photo"])-1]["file_id"];
            foreach($users as $uid => $u){
                $result = sendPhoto($uid, $file_id, "📢 <b>Announcement</b>\n\n$caption");
                if($result !== false) $sent++; else $failed++;
                usleep(50000);
            }
        }
        else if(isset($update["message"]["video"])){
            $file_id = $update["message"]["video"]["file_id"];
            foreach($users as $uid => $u){
                $result = sendVideo($uid, $file_id, "📢 <b>Announcement</b>\n\n$caption");
                if($result !== false) $sent++; else $failed++;
                usleep(50000);
            }
        }
        else if(isset($update["message"]["voice"])){
            $file_id = $update["message"]["voice"]["file_id"];
            foreach($users as $uid => $u){
                $result = sendVoice($uid, $file_id, "📢 <b>Announcement</b>\n\n$caption");
                if($result !== false) $sent++; else $failed++;
                usleep(50000);
            }
        }
        else if(isset($update["message"]["document"])){
            $file_id = $update["message"]["document"]["file_id"];
            foreach($users as $uid => $u){
                $result = sendDocument($uid, $file_id, "📢 <b>Announcement</b>\n\n$caption");
                if($result !== false) $sent++; else $failed++;
                usleep(50000);
            }
        }
        
        $temp[$user_id]['waiting'] = "";
        file_put_contents("temp.json", json_encode($temp));
        
        sendMessage($chat_id, "✅ <b>Broadcast Complete!</b>\n\nTotal Users: ".count($users)."\n✅ Sent: $sent\n❌ Failed: $failed");
        sendAdminPanel($chat_id, 0);
    }
}

// ============================================================
// ======================== FUNCTIONS ==========================
// ============================================================


function customEmojiText($emoji_id, $text = "") {
    $emoji = "\xF0\x9F\x91\x88"; // fallback glyph; entity controls Telegram rendering
    return [
        'text' => $emoji . $text,
        'entities' => [[
            'type' => 'custom_emoji',
            'offset' => 0,
            'length' => mb_strlen($emoji),
            'custom_emoji_id' => (string)$emoji_id
        ]]
    ];
}

function sendAdminPanel($chat_id, $message_id = 0){
    $users = json_decode(file_get_contents("users.json"), true);
    if(!is_array($users)) $users = [];
    $settings = json_decode(file_get_contents("settings.json"), true);
    if(!is_array($settings)) $settings = [];
    $totalUsers = count($users);
    $maintenanceOn = !empty($settings['maintenance_mode']);
    $maintenanceText = $maintenanceOn ? "🟢 ON" : "🔴 OFF";

    $msg =
        "<blockquote><tg-emoji emoji-id=\"6093562529978522804\">🛒</tg-emoji> — <b>GALU MODZ ADMIN</b> — <tg-emoji emoji-id=\"6093562529978522804\">🛒</tg-emoji></blockquote>\n" .
        "<tg-emoji emoji-id=\"6057728771719435723\">👑</tg-emoji> Welcome, <b>Admin</b>!\n\n" .
        "👥 <b>Total Users:</b> <code>{$totalUsers}</code>\n" .
        "🛠️ <b>Maintenance:</b> <b>{$maintenanceText}</b>\n\n" .
        "────────────────────────────\n\n" .
        "<tg-emoji emoji-id=\"6057728771719435723\">👇</tg-emoji> <b>Choose an Admin option</b>";

    $maintenanceBtn = $maintenanceOn ? "🟢 Maintenance OFF" : "🔴 Maintenance ON";
    $kb = [
        [["📦 Add Product","addprod"]],
        [["➕ Add Plan","addplan"],["🔑 Plan Keys","addplankeys"]],
        [["🗑️ Remove Key","removekey"]],
        [["✏️ Edit Plan","editplan"],["🗑️ Delete Plan","delplan"]],
        [["✏️ Edit Product","editprod"],["🗑️ Delete Product","delprod"]],
        [["📢 Broadcast","broadcast"]],
        [["👥 Total Users","userlist"],["💰 Balance Check","userbalcheck"]],
        [["➕ User Balance Add","adduserbal"]],
        [["🛠️ {$maintenanceBtn}","toggle_maintenance"]],
        [["🚫 STOCK OUT BUTTON","stockout_menu"]],
        [["🛠️ Product MAINTENANCE","product_maintenance"]],
        [["📖 HowTo Link","sethowto"],["💬 Support","setsupport"]],
        [["🔑 API Key","setapi"],["💳 FamPay UPI","setupi"]],
        [["🌐 QR API URL","setqrurl"],["🔎 Verify API URL","setverifyurl"]],
        [["⬅️ Back to Menu","back"]]
    ];

    if($message_id > 0) editMsg($chat_id, $message_id, $msg, btn($kb));
    else sendMessage($chat_id, $msg, btn($kb));
}


function loadProductsSafe(){
    $products = json_decode(file_get_contents("products.json"), true);
    return is_array($products) ? $products : [];
}
function saveProductsSafe($products){
    file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function sendStockOutProducts($chat_id,$message_id){
    $products=loadProductsSafe(); $kb=[];
    foreach($products as $pid=>$p){
        if(!is_array($p)) continue;
        $name=htmlspecialchars((string)($p['name']??'Product'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $kb[]=[[$name,"stockprod_{$pid}"]];
    }
    if(!$kb) $kb=[["No products available","backadmin"]];
    $kb[]=[["⬅️ Back to Admin","backadmin"]];
    editMsg($chat_id,$message_id,"🚫 <b>STOCK OUT BUTTON</b>\n\nProduct select karo, phir exact Days/Hours plan ko STOCK OUT ON/OFF karo.",btn($kb));
}
function sendStockOutPlans($chat_id,$message_id,$pid){
    $products=loadProductsSafe();
    if(!isset($products[$pid])||!is_array($products[$pid])){ editMsg($chat_id,$message_id,"❌ Product nahi mila.",btn([["⬅️ Back","stockout_menu"]])); return; }
    $p=$products[$pid]; $kb=[];
    foreach(($p['plans']??[]) as $i=>$plan){
        if(!is_array($plan)) continue;
        $on=!empty($plan['stock_out']);
        $status=$on?'🔴 STOCK OUT':'🟢 IN STOCK';
        $callback=$on ? "stockplanon_{$pid}_{$i}" : "stockplan_{$pid}_{$i}";
        $kb[]=[[getPlanDurationText($plan)." — ".$status,$callback]];
    }
    if(!$kb) $kb=[["No plans available","stockprod_{$pid}"]];
    $kb[]=[["⬅️ Back","stockout_menu"]];
    editMsg($chat_id,$message_id,"🚫 <b>STOCK OUT</b>\n\n<b>".htmlspecialchars((string)$p['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</b>\n\nRed = STOCK OUT. Dobara click karke ON/OFF karo.",btn($kb));
}
function togglePlanStockOut($chat_id,$message_id,$pid,$idx){
    $products=loadProductsSafe();
    if(!isset($products[$pid]['plans'][$idx])||!is_array($products[$pid]['plans'][$idx])){ editMsg($chat_id,$message_id,"❌ Plan nahi mila.",btn([["⬅️ Back","stockout_menu"]])); return; }
    $products[$pid]['plans'][$idx]['stock_out']=empty($products[$pid]['plans'][$idx]['stock_out']);
    saveProductsSafe($products);
    $on=!empty($products[$pid]['plans'][$idx]['stock_out']);
    $duration=getPlanDurationText($products[$pid]['plans'][$idx]);
    sendStockOutPlans($chat_id,$message_id,$pid);
}
function sendMaintenanceProducts($chat_id,$message_id){
    $products=loadProductsSafe(); $kb=[];
    foreach($products as $pid=>$p){
        if(!is_array($p)) continue;
        $on=!empty($p['maintenance']);
        $status=$on?'🟢 MAINTENANCE ON':'🔴 OFF';
        $name=htmlspecialchars((string)($p['name']??'Product'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $kb[]=[[$name." — ".$status,"maintprod_{$pid}"]];
    }
    if(!$kb) $kb=[["No products available","backadmin"]];
    $kb[]=[["⬅️ Back to Admin","backadmin"]];
    editMsg($chat_id,$message_id,"🛠️ <b>PRODUCT MAINTENANCE</b>\n\nProduct par click karke MAINTENANCE ON/OFF karo. ON hone par buy block hoga aur <b>WAIT FOR NEW UPDATE</b> dikhega.",btn($kb));
}
function toggleProductMaintenance($chat_id,$message_id,$pid){
    $products=loadProductsSafe();
    if(!isset($products[$pid])||!is_array($products[$pid])){ editMsg($chat_id,$message_id,"❌ Product nahi mila.",btn([["⬅️ Back","product_maintenance"]])); return; }
    $products[$pid]['maintenance']=empty($products[$pid]['maintenance']);
    saveProductsSafe($products);
    sendMaintenanceProducts($chat_id,$message_id);
}

// ========== PRODUCT PLANS DISPLAY ==========
function sendProductPlans($chat_id, $message_id, $pid){
    $products = json_decode(file_get_contents("products.json"), true);
    if(!is_array($products)) $products = [];

    if(!isset($products[$pid]) || !is_array($products[$pid])){
        editMsg($chat_id, $message_id, "❌ <b>Product nahi mila</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    // Always use the exact product name stored by Admin in products.json.
    $p = $products[$pid];
    $productName = htmlspecialchars((string)($p['name'] ?? 'Product'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $plans = isset($p['plans']) && is_array($p['plans']) ? $p['plans'] : [];

    if(count($plans) == 0){
        $msg = "<blockquote><tg-emoji emoji-id=\"5312361253610475399\">✅</tg-emoji> <b>PRODUCT: {$productName}</b> <tg-emoji emoji-id=\"5312361253610475399\">❗</tg-emoji></blockquote>

";
        $msg .= "❌ <b>Is product ke liye koi plan nahi hai.</b>
Admin se contact karo.";
        editMsg($chat_id, $message_id, $msg, btn([[ ["Back to Shop","backcat"] ]]));
        return;
    }

    // Keep the exact Admin-added order and original index.
    // Clicking a button always targets the exact plan stored in products.json.
    $displayPlans = [];
    foreach($plans as $originalIndex => $plan){
        if(!is_array($plan)) continue;
        $plan['_original_index'] = $originalIndex;
        $displayPlans[] = $plan;
    }

    $msg = "<blockquote><tg-emoji emoji-id=\"5312361253610475399\">✅</tg-emoji> <b>PRODUCT: {$productName}</b> <tg-emoji emoji-id=\"5312361253610475399\">❗</tg-emoji></blockquote>

";
    $msg .= "<tg-emoji emoji-id=\"6314255623437424545\">🖼️</tg-emoji> <b>CHOOSE YOUR PLAN</b> <tg-emoji emoji-id=\"6314255623437424545\">🖼️</tg-emoji>

";
    $msg .= "<blockquote><tg-emoji emoji-id=\"6255824560470363799\">🗒️</tg-emoji> Select your validity plan below to proceed with the secure order <tg-emoji emoji-id=\"6255824560470363799\">🍎</tg-emoji></blockquote>";

    $kb = [];
    foreach($displayPlans as $plan){
        $durationText = getPlanDurationText($plan);
        $price = isset($plan['price']) && is_numeric($plan['price']) ? (float)$plan['price'] : 0;
        $priceDisplay = rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
        // Show only the real Days and Price saved by Admin. Nothing is hardcoded.
        $isStockOut = !empty($plan['stock_out']);
        $isMaintenance = !empty($products[$pid]['maintenance']);
        // Red button + clear status text for unavailable plans.
        if($isMaintenance){
            $planButtonText = "🔴 " . $durationText . " — Maintenance";
        } elseif($isStockOut){
            $planButtonText = "🔴 " . $durationText . " — Stock Out";
        } else {
            $planButtonText = $durationText . " — ₹" . $priceDisplay;
        }
        $originalIndex = (int)$plan['_original_index'];
        $planCallback = $isMaintenance ? "maintplan_{$pid}_{$originalIndex}" : ($isStockOut ? "planout_{$pid}_{$originalIndex}" : "plan_{$pid}_{$originalIndex}");
        $kb[] = [[$planButtonText, $planCallback]];
    }

    $kb[] = [["Back to Shop", "backcat"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}


function getPlanDurationText($plan){
    if(!is_array($plan)) return "";

    // New plans: show EXACTLY what Admin entered.
    // Do not auto-change Days/Hours, singular/plural, spacing, etc.
    if(isset($plan['duration_label'])){
        $label = trim((string)$plan['duration_label']);
        if($label !== '') return $label;
    }

    // Legacy plans created before this fix.
    if(isset($plan['duration_value']) && is_numeric($plan['duration_value'])){
        $v = (float)$plan['duration_value'];
        $unit = strtolower(trim((string)($plan['duration_unit'] ?? 'days')));
        $vDisplay = ($v == (int)$v) ? (string)(int)$v : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        return $vDisplay . " " . ($unit === 'hour' || $unit === 'hours' ? "Hours" : "Days");
    }
    if(isset($plan['hours']) && is_numeric($plan['hours']) && !isset($plan['days'])){
        return ((int)$plan['hours']) . " Hours";
    }
    if(isset($plan['days']) && is_numeric($plan['days'])){
        return ((int)$plan['days']) . " Days";
    }
    return "";
}
function getPlanDurationFields($text){
    $text = trim($text);
    if(preg_match('/^(\d+(?:\.\d+)?)\s*(day|days|d)$/i', $text, $m)){
        return ['value'=>(float)$m[1], 'unit'=>'days'];
    }
    if(preg_match('/^(\d+(?:\.\d+)?)\s*(hour|hours|hr|hrs|h)$/i', $text, $m)){
        return ['value'=>(float)$m[1], 'unit'=>'hours'];
    }
    return null;
}

function showStockOutWait($chat_id,$message_id,$pid,$plan_index){
    $products=loadProductsSafe();
    if(!isset($products[$pid]) || !is_array($products[$pid])){ editMsg($chat_id,$message_id,"❌ <b>Product nahi mila.</b>",btn([[ ["⬅️ Back to Shop","backcat"] ]])); return; }
    editMsg($chat_id,$message_id,"🚫 <b>WAIT FOR STOCK</b>\n\nYe Days/Hours plan abhi <b>STOCK OUT</b> hai.",btn([[ ["Back to Shop","backcat"] ]]));
}

function showMaintenanceWait($chat_id,$message_id,$pid,$plan_index){
    $products=loadProductsSafe();
    if(!isset($products[$pid]) || !is_array($products[$pid])){
        editMsg($chat_id,$message_id,"❌ <b>Product nahi mila.</b>",btn([[ ["Back to Shop","backcat"] ]]));
        return;
    }

    $p=$products[$pid];
    $updateLink=trim((string)($p['update_link']??''));
    $keyboard=[];

    // Premium custom emoji for Update Files button.
    if($updateLink!=='' && filter_var($updateLink,FILTER_VALIDATE_URL)){
        $keyboard[]=[[
            "text"=>"Update Files",
            "style"=>"primary",
            "icon_custom_emoji_id"=>"6017174676898321263",
            "url"=>$updateLink
        ]];
    }

    // Premium custom emoji for Back to Shop button.
    $keyboard[]=[[
        "text"=>"Back to Shop",
        "callback_data"=>"backcat",
        "style"=>"danger",
        "icon_custom_emoji_id"=>"5877536313623711363"
    ]];

    // Premium custom emoji inside the maintenance message.
    $message = "<tg-emoji emoji-id=\"6312099300746728685\">🛠️</tg-emoji> <b>WAIT MAINTENANCE</b>\n\nYe product abhi maintenance mein hai.";

    if($updateLink !== '' && filter_var($updateLink, FILTER_VALIDATE_URL)){
        $message .= "\n\n<tg-emoji emoji-id=\"6017174676898321263\">📁</tg-emoji> <b>Update Files</b> neeche open karo.";
    }

    editMsg(
        $chat_id,
        $message_id,
        $message,
        json_encode(["inline_keyboard"=>$keyboard],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    );
}

// ========== PURCHASE CONFIRMATION ==========
function showPurchaseConfirm($chat_id, $message_id, $user_id, $pid, $plan_index){
    $products = json_decode(file_get_contents("products.json"), true);
    $balances = json_decode(file_get_contents("balances.json"), true);
    if(!is_array($products)) $products = [];
    if(!is_array($balances)) $balances = [];

    if(!isset($products[$pid]) || !is_array($products[$pid])){
        editMsg($chat_id, $message_id, "❌ <b>Product nahi mila.</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    $p = $products[$pid];
    $plans = isset($p['plans']) && is_array($p['plans']) ? $p['plans'] : [];
    if(!isset($plans[$plan_index]) || !is_array($plans[$plan_index])){
        editMsg($chat_id, $message_id, "❌ <b>Plan nahi mila.</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    $plan = $plans[$plan_index];
    if(!empty($p['maintenance'])){
        editMsg($chat_id,$message_id,"🛠️ <b>WAIT FOR NEW UPDATE</b>

Ye product abhi maintenance mein hai.",btn([["Back to Shop","backcat"]]));
        return;
    }
    if(!empty($plan['stock_out'])){
        editMsg($chat_id,$message_id,"🚫 <b>WAIT FOR STOCK</b>

Ye Days/Hours plan abhi STOCK OUT hai.",btn([["Back to Shop","backcat"]]));
        return;
    }
    $productName = htmlspecialchars((string)($p['name'] ?? 'Product'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $durationText = getPlanDurationText($plan);
    $safeDuration = htmlspecialchars($durationText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $price = isset($plan['price']) && is_numeric($plan['price']) ? (float)$plan['price'] : 0;
    $balance = (float)($balances[$user_id] ?? 0);
    $payable = max(0, round($price - $balance, 2));

    if($price <= 0){
        editMsg($chat_id, $message_id, "❌ <b>Invalid plan price.</b>\n\nAdmin se contact karo.", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    $msg = "<blockquote><tg-emoji emoji-id=\"5312361253610475399\">✅</tg-emoji> <b>CONFIRM YOUR ORDER</b> <tg-emoji emoji-id=\"5312361253610475399\">✅</tg-emoji></blockquote>\n\n"
         . "━━━━━━━━━━━━━━━━━━━━\n\n"
         . "<tg-emoji emoji-id=\"6314482651113724077\">💰</tg-emoji> <b>Product:</b> {$productName}\n"
         . "<tg-emoji emoji-id=\"6314482651113724077\">🔵</tg-emoji> <b>Duration:</b> {$safeDuration}\n"
         . "<tg-emoji emoji-id=\"6195245116207143870\">🔐</tg-emoji> <b>Price:</b> ₹" . formatMoney($price) . "\n"
         . "<tg-emoji emoji-id=\"5967389567781703494\">👛</tg-emoji> <b>Wallet Balance:</b> ₹" . formatMoney($balance) . "\n\n"
         . "━━━━━━━━━━━━━━━━━━━━\n\n"
         . "<tg-emoji emoji-id=\"6312099300746728685\">🚨</tg-emoji> <i>Secure checkout — choose a payment method below</i> 🔗";

    $kb = [];
    if($payable > 0){
        $kb[] = [["💳 Pay ₹" . formatMoney($payable) . " via UPI", "purchase_upi_{$pid}_{$plan_index}"]];
    } else {
        $kb[] = [["✅ Confirm Purchase", "purchase_wallet_{$pid}_{$plan_index}"]];
    }
    $kb[] = [["Back to Shop","backcat"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

// ========== START PRODUCT UPI PAYMENT ==========
function startProductUPIPayment($chat_id, $message_id, $user_id, $pid, $plan_index){
    $products = json_decode(file_get_contents("products.json"), true);
    $balances = json_decode(file_get_contents("balances.json"), true);
    if(!is_array($products)) $products = [];
    if(!is_array($balances)) $balances = [];

    if(!isset($products[$pid]['plans'][$plan_index]) || !is_array($products[$pid]['plans'][$plan_index])){
        editMsg($chat_id, $message_id, "❌ <b>Plan nahi mila.</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    $plan = $products[$pid]['plans'][$plan_index];
    $price = isset($plan['price']) && is_numeric($plan['price']) ? (float)$plan['price'] : 0;
    $balance = (float)($balances[$user_id] ?? 0);
    $payable = round($price - $balance, 2);

    if($price <= 0){
        editMsg($chat_id, $message_id, "❌ <b>Invalid plan price.</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }
    if($payable <= 0){
        buyPlan($chat_id, $message_id, $user_id, $pid, $plan_index);
        return;
    }

    createFamPayPurchaseOrder($chat_id, $message_id, $user_id, $payable, $pid, $plan_index);
}

// ========== CREATE UPI ORDER FOR PRODUCT SHORTFALL ==========
function createFamPayPurchaseOrder($chat_id, $message_id, $user_id, $amount, $pid, $plan_index){
    $settings = json_decode(file_get_contents("settings.json"), true);
    $api_key = $settings['api_key'] ?? "";
    $amount = round((float)$amount, 2);

    if($amount < 1 || $amount > 5000){
        editMsg($chat_id, $message_id, "❌ <b>Payment amount invalid.</b>\n\nAmount: ₹".formatMoney($amount), btn([[["⬅️ Back","backcat"]]]));
        return;
    }

    $url = "https://famgateway.in/api/create-order?amount=".rawurlencode($amount)."&api_key=".rawurlencode($api_key);
    $res = @json_decode(file_get_contents($url), true);

    if(isset($res['status']) && $res['status'] === "success" && isset($res['data']['order_id'])){
        $data = $res['data'];
        $order_id = (string)$data['order_id'];
        $expire_time = time() + (5 * 60);

        $orders = json_decode(file_get_contents("orders.json"), true);
        if(!is_array($orders)) $orders = [];

        $orders[$order_id] = [
            "user" => $user_id,
            "amount" => $amount,
            "status" => "pending",
            "created" => time(),
            "expire" => $expire_time,
            "type" => "product_purchase",
            "product_id" => (string)$pid,
            "plan_index" => (int)$plan_index
        ];
        file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE), LOCK_EX);

        $expire_display = date("H:i:s", $expire_time);
        $msg = "💳 <b>UPI PAYMENT</b>\n\n"
             . "Amount: <b>₹".formatMoney($amount)."</b>\n"
             . "Order ID: <code>".htmlspecialchars($order_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>\n"
             . "UPI ID: <code>".htmlspecialchars((string)($data['upi_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>\n"
             . "⏰ Expire: {$expire_display}\n\n"
             . "Payment complete hone ke baad <b>Check Payment</b> dabao. Payment verify hote hi exact selected product ka key automatically aa jayega.";

        sendPhoto(
            $chat_id,
            $data['qr_url'],
            $msg,
            btn([
                [["🔄 Check Payment","check_$order_id"]],
                [["❌ Cancel Order","cancel_$order_id"]],
                [["⬅️ Back","backcat"]]
            ])
        );
        if($message_id > 0) deleteMsg($chat_id, $message_id);
    } else {
        $error = is_array($res) ? ($res['message'] ?? $res['error'] ?? "Unknown") : "Gateway response invalid";
        editMsg($chat_id, $message_id, "❌ <b>UPI order create nahi hua.</b>\n\nError: ".htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), btn([[["⬅️ Back","backcat"]]]));
    }
}

// ========== BUY PLAN ==========
function buyPlan($chat_id, $message_id, $user_id, $pid, $plan_index){
    global $adminID;
    $products = json_decode(file_get_contents("products.json"), true);
    $balances = json_decode(file_get_contents("balances.json"), true);
    $orders = json_decode(file_get_contents("orders.json"), true);

    if(!is_array($products)) $products = [];
    if(!is_array($balances)) $balances = [];
    if(!is_array($orders)) $orders = [];

    if(!isset($products[$pid]) || !is_array($products[$pid])){
        editMsg($chat_id, $message_id, "❌ <b>Product nahi mila.</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    $p = $products[$pid];
    $plans = isset($p['plans']) && is_array($p['plans']) ? $p['plans'] : [];

    if(!isset($plans[$plan_index]) || !is_array($plans[$plan_index])){
        editMsg($chat_id, $message_id, "❌ <b>Plan nahi mila.</b>", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    $plan = $plans[$plan_index];
    if(!empty($p['maintenance'])){
        editMsg($chat_id,$message_id,"🛠️ <b>WAIT FOR NEW UPDATE</b>

Ye product abhi maintenance mein hai.",btn([["Back to Shop","backcat"]]));
        return;
    }
    if(!empty($plan['stock_out'])){
        editMsg($chat_id,$message_id,"🚫 <b>WAIT FOR STOCK</b>

Ye Days/Hours plan abhi STOCK OUT hai.",btn([["Back to Shop","backcat"]]));
        return;
    }
    $price = isset($plan['price']) && is_numeric($plan['price']) ? (float)$plan['price'] : 0;
    $bal = (float)($balances[$user_id] ?? 0);
    $durationText = getPlanDurationText($plan);
    $apiPid = trim((string)($plan['api_pid'] ?? $p['api_pid'] ?? ''));

    if($price <= 0){
        editMsg($chat_id, $message_id, "❌ <b>Invalid plan price.</b>

Admin se contact karo.", btn([[["Back to Shop","backcat"]]]));
        return;
    }

    if($bal < $price){
        editMsg($chat_id, $message_id, "❌ <b>Insufficient Balance</b>

📦 Product: ".htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."
📅 Validity: ".htmlspecialchars($durationText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."
💰 Price: ₹".formatMoney($price)."
💳 Balance: ₹".formatMoney($bal), btn([[["💰 Add Balance","addbal"]],[["⬅️ Back","backcat"]]]));
        return;
    }

    /*
     * KEY DELIVERY:
     * 1) API PID available -> try reseller API first.
     * 2) If API fails or returns no key -> use one manual key from this plan.
     * 3) If neither source has a key, do NOT deduct the user's balance.
     */
    $key = "";
    $source = "";
    $apiOrderId = "";

    if($apiPid !== ''){
        $api = resellerApiBuy($apiPid, $durationText);
        if($api['ok'] && trim((string)($api['key'] ?? '')) !== ''){
            $key = trim((string)$api['key']);
            $source = "api";
            $apiOrderId = (string)($api['order_id'] ?? '');
        }
    }

    // Manual fallback: take the first available key from the exact purchased plan.
    if($key === ''){
        $manualKeys = isset($products[$pid]['plans'][$plan_index]['keys']) && is_array($products[$pid]['plans'][$plan_index]['keys'])
            ? $products[$pid]['plans'][$plan_index]['keys'] : [];

        // Remove blank entries safely.
        $manualKeys = array_values(array_filter(array_map('trim', $manualKeys), function($v){
            return $v !== '';
        }));

        if(count($manualKeys) > 0){
            $key = array_shift($manualKeys);
            $products[$pid]['plans'][$plan_index]['keys'] = $manualKeys;
            $source = "manual";
            file_put_contents("products.json", json_encode($products, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }

    if($key === ''){
        editMsg($chat_id, $message_id, "❌ <b>Key Available Nahi Hai</b>

API se key nahi mili aur is plan me manual key bhi available nahi hai.

💳 Aapka balance deduct nahi hua.", btn([[["Try Again","plan_{$pid}_{$plan_index}"]],[["Back to Shop","backcat"]]]));
        return;
    }

    // Deduct only after a real API/manual key has been obtained.
    $newBalance = round($bal - $price, 2);
    $balances[$user_id] = $newBalance;

    $order_id = "ORD".date("YmdHis").rand(100,999);
    while(isset($orders[$order_id])) $order_id = "ORD".date("YmdHis").rand(100,999);

    $orders[$order_id] = [
        "user" => $user_id,
        "product_id" => $pid,
        "product_name" => $p['name'],
        "api_pid" => $apiPid,
        "plan" => $plan,
        "price" => $price,
        "duration" => $durationText,
        "days" => (int)($plan['days'] ?? 0),
        "hours" => (int)($plan['hours'] ?? 0),
        "status" => "Delivered",
        "date" => date("d M Y H:i"),
        "key" => $key,
        "key_source" => $source,
        "reseller_api_order_id" => $apiOrderId
    ];

    file_put_contents("balances.json", json_encode($balances, JSON_UNESCAPED_UNICODE), LOCK_EX);
    file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE), LOCK_EX);

    // ========== ADMIN PURCHASE NOTIFICATION ==========
    // Load the buyer directly here because buyPlan() has its own function scope.
    // Notify admin only after key delivery, balance deduction and order save succeed.
    $allUsersForNotify = json_decode(file_get_contents("users.json"), true);
    if(!is_array($allUsersForNotify)) $allUsersForNotify = [];

    $buyerName = trim((string)($allUsersForNotify[$user_id]["name"] ?? "User"));
    if($buyerName === "") $buyerName = "User";
    $buyerUsername = trim((string)($allUsersForNotify[$user_id]["username"] ?? ""));

    $buyerDisplay = htmlspecialchars($buyerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if($buyerUsername !== ""){
        $buyerDisplay .= " (@" . htmlspecialchars(ltrim($buyerUsername, "@"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ")";
    }

    $sourceLabel = ($source === "api") ? "API" : "MANUAL";
    $adminProduct = htmlspecialchars((string)($p['name'] ?? 'Unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $adminPlan = htmlspecialchars((string)$durationText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $adminKey = htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $adminPrice = formatMoney($price);
    $adminRemaining = formatMoney($newBalance);

    $adminPurchaseMsg =
        "🔔 <b>NEW PURCHASE DELIVERED ({$sourceLabel}) ✔️</b>\n\n"
        . "👤 <b>Buyer:</b> {$buyerDisplay}\n"
        . "📦 <b>Product:</b> {$adminProduct}\n"
        . "⏳ <b>Plan:</b> {$adminPlan}\n"
        . "💸 <b>Price Deducted:</b> ₹{$adminPrice}\n"
        . "💳 <b>User Remaining Bal:</b> ₹{$adminRemaining}\n"
        . "🔑 <b>Key:</b> <code>{$adminKey}</code>";

    // Never let a Telegram notification failure interrupt a successful purchase.
    // Send directly to the configured admin chat ID.
    // This is intentionally after successful delivery + balance/order save.
    $notifyResult = sendMessage((string)$adminID, $adminPurchaseMsg);

    // Fallback retry once if Telegram/network returned an empty response.
    if($notifyResult === false || $notifyResult === null || trim((string)$notifyResult) === ""){
        usleep(250000);
        @sendMessage((string)$adminID, $adminPurchaseMsg);
    }

    // Existing one-time referral reward behavior.
    $users = json_decode(file_get_contents("users.json"), true);
    if(!is_array($users)) $users = [];

    if(isset($users[$user_id]["referrer_id"]) && empty($users[$user_id]["referral_rewarded"])){
        $referrerId = (string)$users[$user_id]["referrer_id"];
        if($referrerId !== (string)$user_id && isset($users[$referrerId])){
            $balances[$referrerId] = round((float)($balances[$referrerId] ?? 0) + 2, 2);
            $users[$user_id]["referral_rewarded"] = true;
            $users[$user_id]["referral_rewarded_at"] = time();
            file_put_contents("balances.json", json_encode($balances, JSON_UNESCAPED_UNICODE), LOCK_EX);
            file_put_contents("users.json", json_encode($users, JSON_UNESCAPED_UNICODE), LOCK_EX);
            sendMessage($referrerId, "🎉 <b>Referral Reward!</b>

Your friend completed their first purchase.
💰 ₹2.00 added to your wallet.");
        }
    }

    $productName = htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeDuration = htmlspecialchars($durationText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeKey = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sourceLabel = $source === "api" ? "API" : "MANUAL";

    // Purchase-success layout requested in the reference image.
    // Premium custom emojis for the purchase-success message.
    // IMPORTANT: keep these as Telegram HTML entities, not button text tags.
    $msg = "<tg-emoji emoji-id=\"5350447674971660988\">🎮</tg-emoji> <b>PURCHASE SUCCESSFUL!</b>

"
        ."<tg-emoji emoji-id=\"6314482651113724077\">💰</tg-emoji> <b>Product:</b> {$productName}
"
        ."<tg-emoji emoji-id=\"6005570495603282482\">🔑</tg-emoji> <b>Validity:</b> {$safeDuration}
"
        ."<tg-emoji emoji-id=\"6005570495603282482\">🛡️</tg-emoji> <b>Your Key:</b> <code>{$safeKey}</code>

"
        ."━━━ <b>BALANCE DETAILS</b> ━━━

"
        ."<tg-emoji emoji-id=\"6028530359975548369\">💎</tg-emoji> <b>Total Invest:</b> ₹".formatMoney($price)."
"
        ."<tg-emoji emoji-id=\"5897958754267174109\">💵</tg-emoji> <b>New Wallet Balance:</b> ₹".formatMoney($newBalance)."

"
        ."<i>Enjoy your purchase.</i>";

    $updateLink = trim((string)($p['update_link'] ?? ''));
    $keyboard = [];
    // Telegram's native Copy Text button copies the exact delivered key.
    $keyboard[] = [[
        "text" => "📋 Copy Key",
        "style" => "success",
        "copy_text" => ["text" => $key]
    ]];

    if($updateLink !== '' && filter_var($updateLink, FILTER_VALIDATE_URL)){
        $keyboard[] = [[
            "text" => "Update Files",
            "style" => "primary",
            "icon_custom_emoji_id" => "5967456680940671207",
            "url" => $updateLink
        ]];
    }

    $keyboard[] = [[
        "text" => "Back to Menu",
        "style" => "primary",
        "icon_custom_emoji_id" => "5877536313623711363",
        "callback_data" => "back"
    ]];

    editMsg($chat_id, $message_id, $msg, json_encode(
        ["inline_keyboard" => $keyboard],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

function formatMoney($amount){
    return rtrim(rtrim(number_format((float)$amount,2,'.',''),'0'),'.');
}

function resellerApiBuy($productId,$duration){
    $settings=json_decode(file_get_contents("settings.json"),true);
    if(!is_array($settings)) $settings=[];
    $url=trim((string)($settings['reseller_api_url']??''));
    $apiKey=trim((string)($settings['reseller_api_key']??''));
    $masterKey=trim((string)($settings['reseller_master_key']??''));
    if($url===''||$apiKey===''||$masterKey==='') return ['ok'=>false,'key'=>'','message'=>'Reseller API configuration missing.'];
    if(!filter_var($url,FILTER_VALIDATE_URL)) return ['ok'=>false,'key'=>'','message'=>'Reseller API URL invalid hai.'];

    $payload=['api_key'=>$apiKey,'action'=>'buy','product_id'=>$productId,'duration'=>$duration];
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($payload,'','&'),
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','x-master-key: '.$masterKey],
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_USERAGENT=>'GALU-MODZ-Reseller/2.0',
        CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2
    ]);
    $raw=curl_exec($ch); $errno=curl_errno($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($raw===false||$errno) return ['ok'=>false,'key'=>'','message'=>'API connection error (HTTP '.$http.'). Please try again.'];
    $json=json_decode(trim((string)$raw),true);
    if(!is_array($json)) return ['ok'=>false,'key'=>'','message'=>'API returned invalid JSON (HTTP '.$http.').'];

    $status=strtolower(trim((string)($json['status']??$json['success']??'')));
    $success=in_array($status,['success','successful','true','1','ok'],true)
        || (($json['success']??null)===true)
        || (isset($json['code'])&&(string)$json['code']==='200'&&!isset($json['error']));
    if(!$success){
        $message=findApiValue($json,['message','error','msg','detail','reason']);
        if($message==='') $message='API rejected the purchase (HTTP '.$http.').';
        return ['ok'=>false,'key'=>'','message'=>$message];
    }
    $key=findApiValue($json,['key','license_key','license','product_key','serial']);
    $orderId=findApiValue($json,['order_id','orderId','transaction_id','transactionId','id']);
    if($key==='') return ['ok'=>false,'key'=>'','message'=>'API purchase succeeded but no license key was returned.'];
    return ['ok'=>true,'key'=>$key,'message'=>'','order_id'=>$orderId];
}

function findApiValue($data,$wantedKeys){
    if(!is_array($data)) return '';
    foreach($wantedKeys as $wanted){
        foreach($data as $k=>$v){
            if(strtolower((string)$k)===strtolower($wanted)&&is_scalar($v)&&trim((string)$v)!=='') return trim((string)$v);
        }
    }
    foreach($data as $v){ if(is_array($v)){ $x=findApiValue($v,$wantedKeys); if($x!=='') return $x; } }
    return '';
}

// ========== ADMIN KEY REMOVE FUNCTIONS ==========
function sendSelectProductForRemoveKey($chat_id,$message_id){
 $products=json_decode(file_get_contents("products.json"),true); if(!is_array($products))$products=[];
 if(!$products){editMsg($chat_id,$message_id,"❌ <b>Koi Product nahi hai!</b>\n\nPehle product aur plan add karo.",btn([[ ["⬅️ Back","backadmin"] ]]));return;}
 $kb=[]; foreach($products as $id=>$p){$name=htmlspecialchars((string)($p["name"]??"Product"),ENT_QUOTES|ENT_SUBSTITUTE,"UTF-8");$n=is_array($p["plans"]??null)?count($p["plans"]):0;$kb[]=[[$name." (".$n." plans)","remkeyprod_".$id]];} $kb[]=[["⬅️ Back","backadmin"]];
 editMsg($chat_id,$message_id,"🗑️ <b>Remove Key</b>\n\nKis product ki key remove karni hai?",btn($kb));
}
function sendPlansForRemoveKey($chat_id,$message_id,$pid){
 $products=json_decode(file_get_contents("products.json"),true); if(!isset($products[$pid])){editMsg($chat_id,$message_id,"❌ <b>Product nahi mila.</b>",btn([[ ["⬅️ Back","removekey"] ]]));return;} $p=$products[$pid];$plans=is_array($p["plans"]??null)?$p["plans"]:[];
 if(!$plans){editMsg($chat_id,$message_id,"❌ Is product me koi plan nahi hai.",btn([[ ["⬅️ Back","removekey"] ]]));return;} $kb=[]; foreach($plans as $i=>$pl){$dur=getPlanDurationText($pl);$price=is_numeric($pl["price"]??null)?formatMoney((float)$pl["price"]):"0";$n=is_array($pl["keys"]??null)?count($pl["keys"]):0;$kb[]=[[$dur." - ₹".$price." (".$n." keys)","remkeyplan_{$pid}_{$i}"]];}$kb[]=[["⬅️ Back","removekey"]];
 editMsg($chat_id,$message_id,"🗑️ <b>Remove Key - ".htmlspecialchars((string)($p["name"]??"Product"),ENT_QUOTES|ENT_SUBSTITUTE,"UTF-8")."</b>\n\nKis plan ki key remove karni hai?",btn($kb));
}
function sendKeysForRemove($chat_id,$message_id,$pid,$plan_index){
 $products=json_decode(file_get_contents("products.json"),true); if(!isset($products[$pid]["plans"][$plan_index])){editMsg($chat_id,$message_id,"❌ <b>Plan nahi mila.</b>",btn([[ ["⬅️ Back","removekey"] ]]));return;} $pl=$products[$pid]["plans"][$plan_index];$keys=is_array($pl["keys"]??null)?array_values($pl["keys"]):[];$dur=htmlspecialchars(getPlanDurationText($pl),ENT_QUOTES|ENT_SUBSTITUTE,"UTF-8");
 if(!$keys){editMsg($chat_id,$message_id,"🔑 <b>{$dur}</b>\n\n❌ Is plan me koi key available nahi hai.",btn([[ ["⬅️ Back","remkeyprod_".$pid] ]]));return;}
 $kb=[]; foreach($keys as $i=>$key){$k=trim((string)$key);if($k==="")$k="(empty key)";$label="🗑️ ".(mb_strlen($k)>35?mb_substr($k,0,35)."…":$k);$kb[]=[[$label,"remkey_{$pid}_{$plan_index}_{$i}"]];}$kb[]=[["⬅️ Back","remkeyprod_".$pid]];
 editMsg($chat_id,$message_id,"🗑️ <b>Remove Key</b>\n\nPlan: <b>{$dur}</b>\nTotal Keys: <b>".count($keys)."</b>\n\nJis key ko remove karna hai us par click karo:",btn($kb));
}
function removeSinglePlanKey($chat_id,$message_id,$pid,$plan_index,$key_index){
 $products=json_decode(file_get_contents("products.json"),true);if(!isset($products[$pid]["plans"][$plan_index]["keys"])||!is_array($products[$pid]["plans"][$plan_index]["keys"])){editMsg($chat_id,$message_id,"❌ <b>Key list nahi mili.</b>",btn([[ ["⬅️ Back","removekey"] ]]));return;}
 $keys=array_values($products[$pid]["plans"][$plan_index]["keys"]);if(!isset($keys[$key_index])){editMsg($chat_id,$message_id,"❌ <b>Key nahi mili.</b>\n\nList refresh karke dobara try karo.",btn([[ ["⬅️ Back","removekey"] ]]));return;}$removed=(string)$keys[$key_index];unset($keys[$key_index]);$products[$pid]["plans"][$plan_index]["keys"]=array_values($keys);
 if(file_put_contents("products.json",json_encode($products,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)===false){editMsg($chat_id,$message_id,"❌ <b>Key remove nahi ho payi.</b>",btn([[ ["⬅️ Back","removekey"] ]]));return;}
 $safe=htmlspecialchars($removed,ENT_QUOTES|ENT_SUBSTITUTE,"UTF-8");$dur=htmlspecialchars(getPlanDurationText($products[$pid]["plans"][$plan_index]),ENT_QUOTES|ENT_SUBSTITUTE,"UTF-8");$left=count($products[$pid]["plans"][$plan_index]["keys"]);
 editMsg($chat_id,$message_id,"✅ <b>Key Remove Ho Gayi!</b>\n\n🔑 Key: <code>{$safe}</code>\n📅 Plan: <b>{$dur}</b>\n📦 Remaining Keys: <b>{$left}</b>",btn([[ ["🗑️ Remove Another Key","remkeyplan_{$pid}_{$plan_index}"] ],[["⬅️ Admin Panel","backadmin"]]]));
}

// ========== ADMIN SELECT FUNCTIONS ==========
function sendSelectProductForAddPlan($chat_id, $message_id){
    $products = json_decode(file_get_contents("products.json"), true);
    if(count($products) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Koi Product nahi hai!</b>\n\nPehle '📦 Add Product' se product add karo.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "➕ <b>Kis Product me Plan Add karna hai?</b>";
    $kb = [];
    foreach($products as $id => $p){
        $plan_count = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [["{$p['name']} (".$plan_count." plans)", "addplanprod_$id"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendSelectProductForAddPlanKeys($chat_id, $message_id){
    $products = json_decode(file_get_contents("products.json"), true);
    if(count($products) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "🔑 <b>Kis Product ke Plan me Keys Add karna hai?</b>";
    $kb = [];
    foreach($products as $id => $p){
        $plan_count = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [["{$p['name']} (".$plan_count." plans)", "addkeysprod_$id"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendPlansForAddKeys($chat_id, $message_id, $pid){
    $products = json_decode(file_get_contents("products.json"), true);
    $p = $products[$pid];
    if(!isset($p['plans']) || count($p['plans']) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Is product me koi plan nahi hai!</b>\n\nPehle '➕ Add Plan' se plan add karo.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "🔑 <b>Add Keys - {$p['name']}</b>\n\nKis plan me keys add karna hai?";
    $kb = [];
    foreach($p['plans'] as $index => $plan){
        $keys_count = isset($plan['keys']) ? count($plan['keys']) : 0;
        $durationText = getPlanDurationText($plan);
        $kb[] = [[$durationText . " - ₹" . $plan['price'] . " (" . $keys_count . " keys)", "addkeysplan_{$pid}_{$index}"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendSelectProductForEditPlan($chat_id, $message_id){
    $products = json_decode(file_get_contents("products.json"), true);
    if(count($products) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "✏️ <b>Kis Product ka Plan Edit karna hai?</b>";
    $kb = [];
    foreach($products as $id => $p){
        $plan_count = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [["{$p['name']} (".$plan_count." plans)", "editplanprod_$id"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendPlansForEdit($chat_id, $message_id, $pid){
    $products = json_decode(file_get_contents("products.json"), true);
    $p = $products[$pid];
    if(!isset($p['plans']) || count($p['plans']) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Is product me koi plan nahi hai!</b>\n\nPehle '➕ Add Plan' se plan add karo.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "✏️ <b>Edit Plan - {$p['name']}</b>\n\nKis plan ko edit karna hai?";
    $kb = [];
    foreach($p['plans'] as $index => $plan){
        $keys_count = isset($plan['keys']) ? count($plan['keys']) : 0;
        $durationText = getPlanDurationText($plan);
        $kb[] = [[$durationText . " - ₹" . $plan['price'] . " (" . $keys_count . " keys)", "editplan_{$pid}_{$index}"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendSelectProductForDeletePlan($chat_id, $message_id){
    $products = json_decode(file_get_contents("products.json"), true);
    if(count($products) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "🗑️ <b>Kis Product ka Plan Delete karna hai?</b>";
    $kb = [];
    foreach($products as $id => $p){
        $plan_count = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [["{$p['name']} (".$plan_count." plans)", "delplanprod_$id"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendPlansForDelete($chat_id, $message_id, $pid){
    $products = json_decode(file_get_contents("products.json"), true);
    $p = $products[$pid];
    if(!isset($p['plans']) || count($p['plans']) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Is product me koi plan nahi hai!</b>", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "🗑️ <b>Delete Plan - {$p['name']}</b>\n\nKis plan ko delete karna hai?";
    $kb = [];
    foreach($p['plans'] as $index => $plan){
        $durationText = getPlanDurationText($plan);
        $kb[] = [["❌ " . $durationText . " - ₹" . $plan['price'], "delplan_{$pid}_{$index}"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendSelectProductForDelete($chat_id, $message_id){
    $products = json_decode(file_get_contents("products.json"), true);
    if(count($products) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Koi Product nahi hai!</b>\n\nPehle '📦 Add Product' se product add karo.", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "🗑️ <b>Kis Product ko Delete karna hai?</b>\n\n⚠️ <b>Warning:</b> Product delete karne se uske saare plans aur keys bhi delete ho jayenge!";
    $kb = [];
    foreach($products as $id => $p){
        $plan_count = isset($p['plans']) ? count($p['plans']) : 0;
        $kb[] = [["❌ {$p['name']} (".$plan_count." plans)", "delprod_$id"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendSelectProductForEdit($chat_id, $message_id){
    $products = json_decode(file_get_contents("products.json"), true);
    if(count($products) == 0){
        editMsg($chat_id, $message_id, "❌ <b>Koi Product nahi hai!</b>", btn([[["⬅️ Back","backadmin"]]]));
        return;
    }
    $msg = "✏️ <b>Kis Product ko Edit karna hai?</b>";
    $kb = [];
    foreach($products as $id => $p){
        $kb[] = [["{$p['name']} (".count($p['plans'])." plans)", "editprod_$id"]];
    }
    $kb[] = [["⬅️ Back","backadmin"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

// ========== USER FUNCTIONS ==========
function sendMainMenu($chat_id, $name, $balance, $message_id = 0){
    $safeName = htmlspecialchars((string)$name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBalance = number_format((float)$balance, 2, '.', '');

    // Main screen is intentionally built to mirror the supplied reference image:
    // quote cards, spacing, wording, button order and button colours.
    $msg =
        "<blockquote><tg-emoji emoji-id=\"6093562529978522804\">🛒</tg-emoji> ━ <b>𝗚𝗔𝗟𝗨 𝗠𝗢𝗗𝗭 𝗦𝗧𝗢𝗥𝗘</b> ━ <tg-emoji emoji-id=\"6093562529978522804\">🛒</tg-emoji></blockquote>\n" .
        "<tg-emoji emoji-id=\"6057728771719435723\">✅</tg-emoji> Welcome, <b>{$safeName}</b>!\n\n" .
        "<blockquote><tg-emoji emoji-id=\"6285210748184434476\">⬇️</tg-emoji> Premium Game Keys\n" .
        "<tg-emoji emoji-id=\"6282977077427702833\">⚡</tg-emoji> Instant Delivery 24/7\n" .
        "<tg-emoji emoji-id=\"6165821484968188344\">🔐</tg-emoji> 100% Secure Payment\n" .
        "<tg-emoji emoji-id=\"6093382540784046658\">📊</tg-emoji> Best Prices Guaranteed\n" .
        "<tg-emoji emoji-id=\"6120875335115347828\">🎉</tg-emoji> Safe And Trusted\n" .
        "<tg-emoji emoji-id=\"5472239203590888751\">✉️</tg-emoji> Professional Support</blockquote>\n\n" .
        "────────────────────────────\n\n" .
        "<blockquote><tg-emoji emoji-id=\"6210859306602995217\">💰</tg-emoji> <b>Wallet Balance:</b> ₹{$safeBalance}</blockquote>\n\n" .
        "────────────────────────────\n\n" .
        "<tg-emoji emoji-id=\"6057728771719435723\">👇</tg-emoji> <b>Tap Shop Now to Start!</b>";

    // Same 1 + 2 + 2 + 2 layout as the reference screenshot.
    // HOW TO USE is a direct URL button: one tap opens the configured link.
    $settingsNow = json_decode(file_get_contents("settings.json"), true);
    if(!is_array($settingsNow)) $settingsNow = [];
    $howtoLink = normalizeWebLink($settingsNow["howto_link"] ?? "", "https://t.me/YourHowToVideo");

    $kb = [
        [makeButton("Shop / Store Product","shop")],
        [makeButton("Add Balance","addbal"), makeButton("My Profile","profile")],
        [makeUrlButton("How To Use", $howtoLink, "5427158904729513162"), makeButton("Lucky","lucky")],
        [makeButton("Share","share_page"), makeButton("Support","support")]
    ];

    $replyMarkup = json_encode(["inline_keyboard"=>$kb], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    if($message_id > 0) editMsg($chat_id, $message_id, $msg, $replyMarkup);
    else sendMessage($chat_id, $msg, $replyMarkup);
}

function sendLucky($chat_id, $message_id){
    $users = json_decode(file_get_contents("users.json"), true);
    $user_id = $chat_id;
    $lastClaim = isset($users[$user_id]["daily_reward_at"]) ? (int)$users[$user_id]["daily_reward_at"] : 0;
    $remaining = 86400 - (time() - $lastClaim);

    if($lastClaim > 0 && $remaining > 0){
        $hours = floor($remaining / 3600);
        $mins = floor(($remaining % 3600) / 60);
        $msg = "<tg-emoji emoji-id=\"6001538474795078519\">🎁</tg-emoji> <b>Daily Reward</b>

Already claimed ₹0.50.
Next claim: {$hours}h {$mins}m";
        editMsg($chat_id, $message_id, $msg, btn([[["Back to Menu","back"]]]));
        return;
    }

    $balances = json_decode(file_get_contents("balances.json"), true);
    $balances[$user_id] = (float)($balances[$user_id] ?? 0) + 0.50;
    $users[$user_id]["daily_reward_at"] = time();

    file_put_contents("balances.json", json_encode($balances, JSON_UNESCAPED_UNICODE));
    file_put_contents("users.json", json_encode($users, JSON_UNESCAPED_UNICODE));

    $msg = "<tg-emoji emoji-id=\"6165881541495889665\">🎉</tg-emoji> <b>Daily Reward</b>

₹0.50 added to your wallet!
<tg-emoji emoji-id=\"5845947563601041174\">⏰</tg-emoji> You can claim again after 24 hours.";
    editMsg($chat_id, $message_id, $msg, btn([[["Back to Menu","back"]]]));
}

function sendReferral($chat_id, $message_id, $user_id){
    $users = json_decode(file_get_contents("users.json"), true);
    $refLink = "https://t.me/" . getBotUsername() . "?start=ref" . $user_id;

    // Real referral count = users who actually joined through this user's referral link.
    // Rewarded referrals are tracked separately and become successful after first purchase.
    $referrals = 0;
    $successful = 0;
    foreach($users as $uid => $u){
        if(isset($u["referrer_id"]) && (string)$u["referrer_id"] === (string)$user_id){
            $referrals++;
            if(!empty($u["referral_rewarded"])) $successful++;
        }
    }

    $safeLink = htmlspecialchars($refLink, ENT_QUOTES, "UTF-8");
    $msg = "<tg-emoji emoji-id=\"6253472490350185356\">🔗</tg-emoji> <b>Share & Refer</b>\n\n" .
           "<tg-emoji emoji-id=\"6093382540784046658\">🔗</tg-emoji> <b>Your Referral Link:</b>\n<a href=\"$safeLink\">$safeLink</a>\n\n" .
           "<tg-emoji emoji-id=\"5256143829672672750\">👥</tg-emoji> <b>Referrals:</b> {$referrals}\n" .
           "<tg-emoji emoji-id=\"6311799464784827510\">🎉</tg-emoji> <b>Successful Referrals:</b> {$successful}\n\n" .
           "<tg-emoji emoji-id=\"6210859306602995217\">💰</tg-emoji> You earn ₹2.00 when your friend makes their first purchase (one-time per friend).";

    editMsg($chat_id, $message_id, $msg, btn([
        [["Share Link","share_link"]],
        [["Back to Menu","back"]]
    ]));
}

function getBotUsername(){
    $cacheFile = "bot_username.json";
    if(file_exists($cacheFile)){
        $cached = json_decode(file_get_contents($cacheFile), true);
        if(!empty($cached["username"])) return ltrim($cached["username"], "@");
    }

    global $website;
    $res = @json_decode(file_get_contents($website . "/getMe"), true);
    $username = $res["result"]["username"] ?? "YourBotUsername";
    file_put_contents($cacheFile, json_encode(["username"=>$username], JSON_UNESCAPED_UNICODE));
    return ltrim($username, "@");
}

function sendCategories($chat_id, $message_id){
    // Categories removed. Keep this callback compatible by opening Shop directly.
    sendProducts($chat_id, $message_id);
}

function sendProducts($chat_id, $message_id, $cid = null){
    $products = json_decode(file_get_contents("products.json"), true);
    if(!is_array($products)) $products = [];

    // Shop header matches the supplied reference and uses the requested premium emoji.
    $msg = "<blockquote><tg-emoji emoji-id=\"5451937962629544243\">🛒</tg-emoji> <b>PRODUCT STORE — SHOP</b> <tg-emoji emoji-id=\"5451937962629544243\">🛒</tg-emoji></blockquote>\n\n<tg-emoji emoji-id=\"6005570495603282482\">🔥</tg-emoji> <b>Choose a product:</b>";
    $kb = [];
    foreach($products as $id => $p){
        if(!is_array($p) || !isset($p['name'])) continue;
        // Keep the product button text clean: only the admin-added product name.
        // makeButton() applies the requested premium emoji to every buy_* button,
        // so newly added products automatically get the same icon.
        $safeProductName = htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $kb[] = [[$safeProductName, "buy_$id"]];
    }
    if(count($kb) == 0) $msg .= "\n\n❌ Abhi koi product available nahi hai.";
    $kb[] = [["Back","back"]];
    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function sendOrders($chat_id, $message_id, $user_id){
    $orders = json_decode(file_get_contents("orders.json"), true);
    if(!is_array($orders)) $orders = [];

    $my_orders = [];
    foreach($orders as $id => $o){
        if(isset($o['user']) && (string)$o['user'] === (string)$user_id){
            $my_orders[$id] = $o;
        }
    }

    if(count($my_orders) == 0){
        $msg = "📜 <b>ALL HISTORY</b>\n\nNo purchased key yet.";
        editMsg($chat_id, $message_id, $msg, btn([[["« Back to Menu","back"]]]));
        return;
    }

    $my_orders = array_reverse($my_orders, true);
    $msg = "📜 <b>ALL HISTORY</b>\n\n";
    $i = 1;

    foreach($my_orders as $o){
        $product_name = htmlspecialchars((string)($o['product_name'] ?? "Unknown Product"), ENT_QUOTES, 'UTF-8');
        $key = htmlspecialchars((string)($o['key'] ?? ""), ENT_QUOTES, 'UTF-8');
        $plan = (isset($o['plan']) && is_array($o['plan'])) ? $o['plan'] : [];

        $days = null;
        if(isset($o['days']) && is_numeric($o['days'])){
            $days = (int)$o['days'];
        } elseif(isset($plan['days']) && is_numeric($plan['days'])){
            $days = (int)$plan['days'];
        }

        $hours = null;
        if(isset($o['hours']) && is_numeric($o['hours'])){
            $hours = (int)$o['hours'];
        } elseif(isset($plan['hours']) && is_numeric($plan['hours'])){
            $hours = (int)$plan['hours'];
        } elseif($days !== null){
            $hours = $days * 24;
        }

        $duration = trim((string)($o['duration'] ?? ''));
        if($duration === ''){
            if($hours !== null && ($o['plan']['duration_unit'] ?? '') === 'hours'){
                $duration = $hours . " Hours";
            } elseif($days !== null) {
                $duration = $days . " Days";
            } elseif($hours !== null) {
                $duration = $hours . " Hours";
            }
        }

        $msg .= "{$i}. <b>{$product_name}</b>\n";
        $msg .= "🔑 <b>Key:</b> <code>{$key}</code>\n";
        if($duration !== '') $msg .= "📅 <b>Validity:</b> ".htmlspecialchars($duration, ENT_QUOTES, 'UTF-8')."\n";
        $msg .= "\n";
        $i++;
    }

    editMsg($chat_id, $message_id, $msg, btn([[["Back to Menu","back"]]]));
}

function sendProfile($chat_id, $message_id, $user_id){
    global $balances, $users, $orders;

    $name = $users[$user_id]['name'] ?? "User";
    $join = $users[$user_id]['join'] ?? date("d M Y");

    // Keep the real member-since date saved for this user.
    if(isset($users[$user_id]['join_ts']) && is_numeric($users[$user_id]['join_ts'])){
        $join = date("d M Y", (int)$users[$user_id]['join_ts']);
    }

    $balance = $balances[$user_id] ?? 0;
    $userOrders = [];

    foreach($orders as $orderId => $o){
        if(!is_array($o)) continue;
        if(isset($o['user']) && (string)$o['user'] === (string)$user_id && ($o['status'] ?? '') === "Delivered"){
            $o['_order_id'] = (string)$orderId;
            $userOrders[] = $o;
        }
    }

    // Newest purchase first. The order's own saved date is used for the UI.
    usort($userOrders, function($a, $b){
        $ta = isset($a['date']) ? strtotime((string)$a['date']) : 0;
        $tb = isset($b['date']) ? strtotime((string)$b['date']) : 0;
        return $tb <=> $ta;
    });

    $total_orders = count($userOrders);
    $safeName = htmlspecialchars((string)$name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBalance = number_format((float)$balance, 2, '.', '');

    // Profile header + Total Orders stay above the purchased-key section.
    $msg = "<blockquote>"
         . "<tg-emoji emoji-id=\"5256143829672672750\">👤</tg-emoji> <b>YOUR PROFILE</b>"
         . "</blockquote>\n\n"
         . "<tg-emoji emoji-id=\"6008118472066732010\">👹</tg-emoji> <b>Name:</b> {$safeName}\n"
         . "<tg-emoji emoji-id=\"5798678738384195183\">🆔</tg-emoji> <b>User ID:</b> <code>{$user_id}</code>\n"
         . "<tg-emoji emoji-id=\"6194823951714099861\">📅</tg-emoji> <b>Member Since:</b> {$join}\n"
         . "<tg-emoji emoji-id=\"5809671801667589890\">🏷️</tg-emoji> <b>Account Type:</b> Regular\n"
         . "<tg-emoji emoji-id=\"6210859306602995217\">💰</tg-emoji> <b>Balance:</b> ₹{$safeBalance}\n"
         . "<tg-emoji emoji-id=\"5312361253610475399\">🛒</tg-emoji> <b>Total Orders:</b> {$total_orders}\n\n"
         . "<blockquote>🔑 <b>MY PURCHASED KEYS</b> ❞</blockquote>";

    if($total_orders === 0){
        $msg .= "\n<blockquote>📦 No purchased keys yet.</blockquote>";
    } else {
        foreach($userOrders as $o){
            $product = htmlspecialchars((string)($o['product_name'] ?? $o['product'] ?? 'Unknown Product'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            // Use the exact duration saved with this purchase. Do not invent a new value.
            $duration = trim((string)($o['duration'] ?? ''));
            if($duration === ''){
                $days = isset($o['days']) && is_numeric($o['days']) ? (int)$o['days'] : 0;
                $hours = isset($o['hours']) && is_numeric($o['hours']) ? (int)$o['hours'] : 0;
                if($days > 0 && $hours > 0) $duration = $days . ' Days ' . $hours . ' Hours';
                elseif($days > 0) $duration = $days . ' Days';
                elseif($hours > 0) $duration = $hours . ' Hours';
                else $duration = 'N/A';
            }
            $duration = htmlspecialchars($duration, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $key = htmlspecialchars((string)($o['key'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            // Show the exact paid amount, without unnecessary .00 when it is a whole number.
            $rawPrice = isset($o['price']) && is_numeric($o['price']) ? (float)$o['price'] : 0;
            $price = (floor($rawPrice) == $rawPrice)
                ? number_format($rawPrice, 0, '.', '')
                : number_format($rawPrice, 2, '.', '');

            // REAL purchase date from the saved order timestamp/date.
            $rawDate = trim((string)($o['date'] ?? ''));
            $timestamp = $rawDate !== '' ? strtotime($rawDate) : false;
            $orderDate = ($timestamp !== false)
                ? date('n/j/Y', $timestamp)
                : htmlspecialchars($rawDate !== '' ? $rawDate : 'N/A', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            // Each purchase is its own Telegram blockquote, matching the supplied UI:
            // Product -> Duration -> Key -> Paid -> Date.
            $msg .= "\n<blockquote>"
                  . "📦 <b>Product:</b> {$product} ❞\n"
                  . "⏳ <b>Duration:</b> {$duration}\n"
                  . "🔑 <b>Key:</b> {$key}\n"
                  . "💰 <b>Paid:</b> ₹{$price}\n"
                  . "📅 <b>Date:</b> {$orderDate}"
                  . "</blockquote>";
        }
    }

    editMsg($chat_id, $message_id, $msg, btn([[['Back to Menu','back']]]));
}

function sendProof($chat_id, $message_id){ 
    $settings = json_decode(file_get_contents("settings.json"), true); 
    $link = $settings['proof_link']; 
    $msg = "📄 <b>Payment Proof Channel</b>\n\nYaha sabhi payment proof milenge\n🔗 <a href='$link'>Click Here</a>"; 
    editMsg($chat_id, $message_id, $msg, btn([[["Back to Menu","back"]]])); 
}

function sendHowTo($chat_id, $message_id){ 
    $settings = json_decode(file_get_contents("settings.json"), true); 
    $link = $settings['howto_link']; 
    $msg = "📖 <b>How to Use</b>\n\n1. Add Balance\n2. Shop Now\n3. Key Instant Milega\nVideo Tutorial:\n🔗 <a href='$link'>Watch Now</a>"; 
    editMsg($chat_id, $message_id, $msg, btn([[["Back to Menu","back"]]])); 
}

function sendSupport($chat_id, $message_id){ 
    $settings = json_decode(file_get_contents("settings.json"), true); 
    $user = $settings['support_user']; 
    $msg = "<tg-emoji emoji-id=\"5368554037320900698\">📨</tg-emoji> <b>Contact support:</b> <a href='https://t.me/".ltrim($user,'@')."'>$user</a>"; 
    editMsg($chat_id, $message_id, $msg, btn([[["Back to Menu","back"]]])); 
}

function sendAddBalance($chat_id, $message_id, $user_id){
    // Add Balance opens the exact keypad flow directly.
    saveTemp($user_id, "amount", "0");
    sendKeypad($chat_id, $message_id, $user_id, "0");
}

function sendKeypad($chat_id, $message_id, $user_id, $amount){
    $amount = preg_replace('/\\D+/', '', (string)$amount);
    if($amount === '') $amount = "0";
    $amount = ltrim($amount, "0");
    if($amount === '') $amount = "0";

    $safeAmount = htmlspecialchars($amount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $msg =
        "<tg-emoji emoji-id=\"6210859306602995217\">💼</tg-emoji> <b>ADD BALANCE — ENTER AMOUNT</b> <tg-emoji emoji-id=\"6210859306602995217\">🟢</tg-emoji> <b>ON</b>\n\n" .
        "━━━━━━━━━━━━━━━━━━\n\n" .
        "<tg-emoji emoji-id=\"5927169041595634481\">💳</tg-emoji> <b>Payment:</b> UPI PAY\n" .
        "<tg-emoji emoji-id=\"6210859306602995217\">💰</tg-emoji> <b>Amount:</b> ₹{$safeAmount}\n\n" .
        "━━━━━━━━━━━━━━━━━━\n\n" .
        "<tg-emoji emoji-id=\"6311975081702597046\">🟢</tg-emoji> <b>Instant Auto-Credit</b>\n" .
        "<tg-emoji emoji-id=\"6100170496077204999\">🔗</tg-emoji> <b>Verified in Seconds</b>\n\n" .
        "━━━━━━━━━━━━━━━━━━\n\n" .
        "<tg-emoji emoji-id=\"6100170496077204999\">💫</tg-emoji> <i>Enter the amount using the keypad below</i>";

    $kb = [
        [["1","key_1"],["2","key_2"],["3","key_3"]],
        [["4","key_4"],["5","key_5"],["6","key_6"]],
        [["7","key_7"],["8","key_8"],["9","key_9"]],
        [["Clear","key_C"],["0","key_0"],["Confirm","confirm_{$amount}"]],
        [["Back","backkey"]]
    ];

    editMsg($chat_id, $message_id, $msg, btn($kb));
}

function handleKeypad($chat_id, $message_id, $user_id, $key){ 
    $temp = json_decode(file_get_contents("temp.json"), true); 
    $amount = $temp[$user_id]['amount'] ?? "0"; 
    if($key == "C") $amount = "0"; 
    else if($key == "DEL"){ 
        $amount = substr($amount, 0, -1); 
        if($amount == "") $amount = "0"; 
    } else { 
        $amount = $amount == "0" ? $key : $amount.$key; 
    } 
    $temp[$user_id]['amount'] = $amount; 
    file_put_contents("temp.json", json_encode($temp)); 
    sendKeypad($chat_id, $message_id, $user_id, $amount); 
}

// ========== PAYMENT FUNCTIONS ==========
// Small cURL/file_get_contents wrapper with a hard timeout.
// This prevents the bot from getting stuck on "Generating QR Code..."
function httpGet($url, $timeout = 20){
    if(function_exists('curl_init')){
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'GALU-MODZ-Telegram-Bot/1.0'
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => (int)$timeout,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : '';
}


function createFamPayOrder($chat_id, $message_id, $user_id, $amount){
    $settings = json_decode(file_get_contents("settings.json"), true);
    if(!is_array($settings)) $settings = [];

    $api_key = trim((string)($settings['api_key'] ?? ''));
    $upi_id  = trim((string)($settings['fampay_upi'] ?? ''));

    $amount = (float)$amount;
    if($amount < 1 || $amount > 5000){
        editMsg($chat_id, $message_id, "❌ <b>Invalid Amount</b>\n\nAmount ₹1 se ₹5000 ke beech me hona chahiye.", btn([["↩️ Back","backkey"]]));
        return;
    }

    if($api_key === '' || $upi_id === ''){
        editMsg($chat_id, $message_id, "❌ <b>Payment Gateway Not Configured</b>\n\nAdmin panel me FamPay API Key aur UPI ID set karo.", btn([["↩️ Back","backkey"]]));
        return;
    }

    $loading = "⚙️ <b>Generating QR Code for ₹".formatMoney($amount)."...</b>\nPlease wait a moment";
    if($message_id > 0) editMsg($chat_id, $message_id, $loading);
    else sendMessage($chat_id, $loading);

    // FamPay AnujBots documented QR endpoint.
    $query = http_build_query(["upi"=>$upi_id, "amount"=>$amount], '', '&', PHP_QUERY_RFC3986);
    $qrBase = trim((string)($settings['fampay_qr_url'] ?? "https://famgateway.in/api/qr.php"));
    $url = rtrim($qrBase, "?&") . "?" . $query;
    $res = json_decode(httpGet($url, 20), true);

    if(!is_array($res)){
        editMsg($chat_id, $message_id, "❌ <b>QR generate nahi ho saka</b>\n\nGateway ne valid JSON response nahi diya. Dobara try karo.", btn([["↩️ Back","backkey"]]));
        return;
    }

    $data = $res['data'] ?? [];
    if(!is_array($data)) $data = [];

    $status = strtolower((string)($res['status'] ?? ''));
    $order_id = trim((string)($data['order_id'] ?? $res['order_id'] ?? ''));
    $qr_url = trim((string)($data['qr_url'] ?? $res['qr_url'] ?? ''));
    $gatewayAmount = (float)($data['amount'] ?? $amount);

    if($status === "success" && $order_id !== "" && $qr_url !== ""){
        // QR lifetime is 5 minutes. Use the API expiry when valid, but never
        // extend beyond the documented five-minute limit.
        $expire_time = time() + 300;
        $expiresAt = trim((string)($data['expires_at_ist'] ?? ''));
        if($expiresAt !== ''){
            $parsed = strtotime($expiresAt);
            if($parsed !== false) $expire_time = min($expire_time, $parsed);
        }
        if($expire_time <= time()) $expire_time = time() + 300;

        $orders = json_decode(file_get_contents("orders.json"), true);
        if(!is_array($orders)) $orders = [];

        $orders[$order_id] = [
            "user" => $user_id,
            "amount" => round($gatewayAmount > 0 ? $gatewayAmount : $amount, 2),
            "requested_amount" => round($amount, 2),
            "status" => "pending",
            "created" => time(),
            "expire" => $expire_time,
            "gateway" => "fampay_anujbots",
            "upi_id" => (string)($data['upi_id'] ?? $upi_id)
        ];
        file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        $displayAmount = formatMoney((float)$orders[$order_id]['amount']);
        $displayOrder = htmlspecialchars($order_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $displayUpi = htmlspecialchars((string)($data['upi_id'] ?? $upi_id), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $expireDisplay = "5 minutes";

        $msg = "<tg-emoji emoji-id=\"6165881541495889665\">💳</tg-emoji> <b>ADD BALANCE PAYMENT</b>\n\n"
             . "<tg-emoji emoji-id=\"6165881541495889665\">💰</tg-emoji> Amount: <b>₹{$displayAmount}</b>\n"
             . "<tg-emoji emoji-id=\"6312099300746728685\">⏰</tg-emoji> QR Expires: <b>{$expireDisplay}</b>\n\n"
             . "<tg-emoji emoji-id=\"6114086598138137460\">📱</tg-emoji> QR scan karke payment karo.\n"
             . "Payment karne ke baad neeche <b>Verify Payment</b> dabao.\n\n"
             . "<tg-emoji emoji-id=\"6165988155469076483\">⚡</tg-emoji> Payment verify hote hi balance automatically wallet me add ho jayega.";

        $photoResponse = sendPhoto(
            $chat_id, $qr_url, $msg,
            btn([
                [["✅ Verify Payment","check_{$order_id}"]],
                [["❌ Cancel Payment","cancel_{$order_id}"]]
            ])
        );

        $photoJson = json_decode((string)$photoResponse, true);
        if(is_array($photoJson) && !empty($photoJson['ok'])){
            $sentQrMessageId = (int)($photoJson['result']['message_id'] ?? 0);
            if($sentQrMessageId > 0){
                $orders[$order_id]['qr_message_id'] = $sentQrMessageId;
                file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            }
            if($message_id > 0) deleteMsg($chat_id, $message_id);
        } else {
            editMsg($chat_id, $message_id, "❌ <b>QR image send nahi ho payi.</b>\n\nOrder create ho gaya hai, lekin Telegram QR image receive nahi kar saka. Dobara try karo.", btn([["↩️ Back","backkey"]]));
        }
    } else {
        $error = trim((string)($res['message'] ?? $res['error'] ?? 'Unknown gateway error'));
        if(mb_strlen($error) > 180) $error = mb_substr($error, 0, 180) . "...";
        editMsg($chat_id, $message_id, "❌ <b>QR generate nahi ho saka</b>\n\nError: ".htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), btn([["↩️ Back","backkey"]]));
    }
}


function getRealPayerName($data, $res, $user_id){
    // Prefer the actual payer/sender name returned by the payment gateway.
    $candidates = [
        $data['sender_name'] ?? '',
        $data['payer_name'] ?? '',
        $data['customer_name'] ?? '',
        $data['account_name'] ?? '',
        $data['sender'] ?? '',
        $data['payer'] ?? '',
        $data['name'] ?? '',
        $res['sender_name'] ?? '',
        $res['payer_name'] ?? '',
        $res['customer_name'] ?? '',
        $res['account_name'] ?? '',
        $res['sender'] ?? '',
        $res['payer'] ?? '',
        $res['name'] ?? ''
    ];

    foreach ([$data, $res] as $root) {
        if(!is_array($root)) continue;
        foreach (['payer','sender','customer'] as $key){
            if(isset($root[$key]) && is_array($root[$key])){
                foreach(['name','full_name','display_name'] as $nk){
                    if(!empty($root[$key][$nk])) $candidates[] = $root[$key][$nk];
                }
            }
        }
    }

    foreach($candidates as $name){
        if(is_scalar($name)){
            $name = trim((string)$name);
            if($name !== '' && mb_strtolower($name) !== 'null'){
                return $name;
            }
        }
    }

    // Fallback only when the gateway does not provide payer-name data.
    $users = json_decode(@file_get_contents("users.json"), true);
    if(is_array($users) && isset($users[$user_id]['name']) && trim((string)$users[$user_id]['name']) !== ''){
        return trim((string)$users[$user_id]['name']);
    }
    return 'Unknown';
}

function sendAdminWalletBalanceAdded($order, $payerName, $utr, $orderId, $amount){
    global $adminID;

    $payerSafe = htmlspecialchars((string)$payerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $amountSafe = htmlspecialchars(formatMoney((float)$amount), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $utrSafe = htmlspecialchars((string)$utr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $orderSafe = htmlspecialchars((string)$orderId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $userId = htmlspecialchars((string)($order['user'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $msg =
        "🔔 <b>WALLET BALANCE ADDED</b> ✔\n\n"
        . "👤 <b>User:</b> {$payerSafe} ({$userId})\n"
        . "💰 <b>Amount Added:</b> ₹{$amountSafe}\n"
        . "🧾 <b>UTR:</b> <code>{$utrSafe}</code>\n"
        . "🆔 <b>Order ID:</b> <code>{$orderSafe}</code>";

    sendMessage($adminID, $msg);
}

function sendPaymentSuccess($chat_id, $paidAmount, $newBalance, $utr, $successTime = null){
    $utrSafe = htmlspecialchars((string)$utr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $amountSafe = htmlspecialchars((string)$paidAmount, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $balanceSafe = htmlspecialchars((string)$newBalance, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $timeSafe = htmlspecialchars((string)($successTime ?: date("d-m-Y H:i:s")), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $msg =
        "➖➖➖➖➖➖➖➖➖➖\n"
        . "🔔 <b>WALLET BALANCE ADDED</b> ✔\n"
        . "➖➖➖➖➖➖➖➖➖➖\n\n"
        . "💰 <b>Added Amount:</b> ₹{$amountSafe}\n"
        . "📊 <b>New Balance:</b> ₹{$balanceSafe}\n"
        . "➡️ <b>UTR / Ref ID:</b> <code>{$utrSafe}</code>\n"
        . "🕐 <b>Time:</b> {$timeSafe}\n\n"
        . "➖➖➖➖➖➖➖➖➖➖\n\n"
        . "⚪ <i>Your funds have been successfully\n"
        . "credited! 🎉</i>";

    sendMessage($chat_id, $msg, btn([
        [["Shop Now","shop"],["Profile","profile"]]
    ]));
}

function checkPayment($chat_id, $message_id, $order_id){
    $settings = json_decode(file_get_contents("settings.json"), true);
    if(!is_array($settings)) $settings = [];
    $api_key = trim((string)($settings['api_key'] ?? ''));

    $orders = json_decode(file_get_contents("orders.json"), true);
    if(!is_array($orders)) $orders = [];

    $order_id = trim((string)$order_id);
    if($order_id === '' || !isset($orders[$order_id])){
        editMsg($chat_id, $message_id, "❌ <b>Order nahi mila</b>\n\nYe payment order invalid ya delete ho chuka hai.", btn([["⬅️ Back","backkey"]]));
        return;
    }

    $order = $orders[$order_id];
    if((string)($order['user'] ?? '') !== (string)$GLOBALS['user_id']){
        editMsg($chat_id, $message_id, "❌ <b>Unauthorized Payment Order</b>", btn([["⬅️ Back","backkey"]]));
        return;
    }

    // Idempotency: the same verified order can never credit the wallet twice.
    if(($order['status'] ?? '') === "paid"){
        $paidAmount = formatMoney((float)($order['paid_amount'] ?? $order['amount'] ?? 0));
        $newBalance = formatMoney((float)($order['new_balance'] ?? 0));
        $utr = htmlspecialchars((string)($order['utr'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Payment is already verified. Remove the old QR message so the user
        // cannot keep interacting with a completed payment QR.
        deleteMsg($chat_id, $message_id);
        $successTime = date("d-m-Y H:i:s", (int)($order['credited_at'] ?? time()));
        sendPaymentSuccess($chat_id, $paidAmount, $newBalance, $utr, $successTime);
        return;
    }

    if(($order['status'] ?? '') === "cancelled"){
        editMsg($chat_id, $message_id, "❌ <b>Payment Cancelled</b>\n\nThis QR has been cancelled.", btn([["💰 Add Balance Again","again_$order_id"],["👈 Back to Menu","back"]]));
        return;
    }

    if(time() >= (int)($order['expire'] ?? 0)){
        $orders[$order_id]['status'] = "expired";
        file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        deleteMsg($chat_id, $message_id); sendMessage($chat_id, "❌ <b>QR EXPIRED</b>\n\n5 minutes ke andar payment nahi hua. Ye QR ab valid nahi hai.\n\nNaya QR generate karke payment karo.", btn([["➕ New Payment","backkey"],["Back to Menu","back"]]));
        return;
    }

    if($api_key === ''){
        editMsg($chat_id, $message_id, "❌ <b>API Key Missing</b>\n\nAdmin panel me FamPay API Key set karo.", btn([["⬅️ Back","backkey"]]));
        return;
    }

    // FamPay AnujBots documented verify endpoint.
    $query = http_build_query(["order_id"=>$order_id, "api_key"=>$api_key], '', '&', PHP_QUERY_RFC3986);
    $verifyBase = trim((string)($settings['fampay_verify_url'] ?? "https://famgateway.in/api/qr.php"));
    $url = rtrim($verifyBase, "?&") . "?" . $query;
    $res = json_decode(httpGet($url, 15), true);

    if(!is_array($res)){
        editMsg($chat_id, $message_id, "⚠️ <b>Payment verification failed</b>\n\nGateway ne valid response nahi diya. Kuch seconds baad dobara <b>Verify Payment</b> karo.", btn([["🔄 Verify Payment","check_$order_id"],["❌ Cancel Payment","cancel_$order_id"]]));
        return;
    }

    // Gateways can return the payment result in slightly different JSON shapes.
    // Normalize all supported success fields here so Verify Payment does not
    // incorrectly show "not received" after a real payment.
    $data = $res['data'] ?? [];
    if(!is_array($data)) $data = [];

    $statusCandidates = [
        $res['status'] ?? '',
        $res['payment_status'] ?? '',
        $res['transaction_status'] ?? '',
        $data['status'] ?? '',
        $data['payment_status'] ?? '',
        $data['transaction_status'] ?? ''
    ];
    $verifyStatus = '';
    foreach($statusCandidates as $candidate){
        $candidate = strtolower(trim((string)$candidate));
        if($candidate !== ''){
            $verifyStatus = $candidate;
            break;
        }
    }

    $successFlag = ($res['success'] ?? null);
    if($successFlag === true || $successFlag === 1 || strtolower((string)$successFlag) === 'true'){
        $verifyStatus = 'success';
    }

    $utr = trim((string)(
        $data['utr'] ?? $data['utr_number'] ?? $data['utr_id'] ??
        $data['transaction_id'] ?? $res['utr'] ?? $res['utr_number'] ?? ''
    ));

    $verifiedAmount = (float)(
        $data['amount'] ?? $data['paid_amount'] ?? $data['payment_amount'] ??
        $res['amount'] ?? $res['paid_amount'] ?? 0
    );

    // Treat only explicit successful/paid states as a received payment.
    // A UTR plus amount is required before any wallet credit is made.
    $paidStates = ['success','successful','paid','completed','complete','verified','payment_success','payment successful'];
    $isPaid = in_array($verifyStatus, $paidStates, true);

    if($isPaid && $utr !== '' && $verifiedAmount > 0){
        // Prevent accidental credit of an amount different from the QR amount.
        $expectedAmount = (float)($order['amount'] ?? 0);
        if($expectedAmount > 0 && abs($verifiedAmount - $expectedAmount) > 0.01){
            editMsg($chat_id, $message_id, "⚠️ <b>Payment Amount Mismatch</b>\n\nQR Amount: <b>₹".formatMoney($expectedAmount)."</b>\nVerified Amount: <b>₹".formatMoney($verifiedAmount)."</b>\n\nBalance add nahi kiya gaya. Admin se verify karvao.", btn([["⬅️ Menu","back"]]));
            return;
        }

        $targetUser = (string)$order['user'];
        $lockPath = sys_get_temp_dir() . "/alex_fampay_" . hash('sha256', $order_id) . ".lock";
        $lock = @fopen($lockPath, 'c');

        if($lock && !@flock($lock, LOCK_EX)){
            @fclose($lock);
            editMsg($chat_id, $message_id, "⏳ <b>Payment processing...</b>\n\nDobara tap karne ki zarurat nahi hai.", btn([["⬅️ Menu","back"]]));
            return;
        }

        try {
            $latestOrders = json_decode(file_get_contents("orders.json"), true);
            if(!is_array($latestOrders)) $latestOrders = [];

            if(isset($latestOrders[$order_id]) && ($latestOrders[$order_id]['status'] ?? '') === 'paid'){
                $paidAmountValue = (float)($latestOrders[$order_id]['paid_amount'] ?? $verifiedAmount);
                $paidAmount = formatMoney($paidAmountValue);
                $newBalance = formatMoney((float)($latestOrders[$order_id]['new_balance'] ?? 0));
                $savedUtrRaw = (string)($latestOrders[$order_id]['utr'] ?? $utr);
                $savedUtr = htmlspecialchars($savedUtrRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                if(empty($latestOrders[$order_id]['admin_notified'])){
                    $payerName = (string)($latestOrders[$order_id]['sender_name'] ?? getRealPayerName($data, $res, $targetUser));
                    sendAdminWalletBalanceAdded($latestOrders[$order_id], $payerName, $savedUtrRaw, $order_id, $paidAmountValue);
                    $latestOrders[$order_id]['admin_notified'] = true;
                    file_put_contents("orders.json", json_encode($latestOrders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                }
                deleteMsg($chat_id, $message_id);
                $successTime = date("d-m-Y H:i:s", (int)($latestOrders[$order_id]['credited_at'] ?? time()));
                sendPaymentSuccess($chat_id, $paidAmount, $newBalance, $savedUtr, $successTime);
                return;
            }

            $balances = json_decode(file_get_contents("balances.json"), true);
            if(!is_array($balances)) $balances = [];

            $oldBalance = (float)($balances[$targetUser] ?? 0);
            $newBalanceValue = round($oldBalance + $verifiedAmount, 2);
            $balances[$targetUser] = $newBalanceValue;

            $latestOrders[$order_id]['status'] = "paid";
            $latestOrders[$order_id]['paid_amount'] = round($verifiedAmount, 2);
            $latestOrders[$order_id]['utr'] = $utr;
            $latestOrders[$order_id]['transaction_id'] = (string)($data['transaction_id'] ?? '');
            $payerName = getRealPayerName($data, $res, $targetUser);
            $latestOrders[$order_id]['sender_name'] = $payerName;
            $latestOrders[$order_id]['payment_time_ist'] = (string)($data['payment_time_ist'] ?? '');
            $latestOrders[$order_id]['new_balance'] = $newBalanceValue;
            $latestOrders[$order_id]['credited_at'] = time();

            file_put_contents("balances.json", json_encode($balances, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            file_put_contents("orders.json", json_encode($latestOrders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

            if(empty($latestOrders[$order_id]['admin_notified'])){
                sendAdminWalletBalanceAdded(
                    $latestOrders[$order_id],
                    $payerName,
                    $utr,
                    $order_id,
                    $verifiedAmount
                );
                $latestOrders[$order_id]['admin_notified'] = true;
                file_put_contents("orders.json", json_encode($latestOrders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            }

            // Verification succeeded: delete the QR/caption message first,
            // then show the final credited-payment receipt.
            deleteMsg($chat_id, $message_id);
            $successTime = date("d-m-Y H:i:s", (int)($latestOrders[$order_id]['credited_at'] ?? time()));
            sendPaymentSuccess(
                $chat_id,
                formatMoney($verifiedAmount),
                formatMoney($newBalanceValue),
                $utr,
                $successTime
            );
        } finally {
            if($lock){
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
        return;
    }

    // No confirmed payment yet: show the same clean Telegram alert as the
    // reference screenshot. Do not credit balance until the gateway confirms it.
    $cbid = $GLOBALS['update']['callback_query']['id'] ?? '';
    if($cbid !== '') answerCallback($cbid, null, "⚠️ PAYMENT NOT RECEIVED YET\n\nPlease complete the payment first.");

    $remaining = max(0, (int)($order['expire'] ?? 0) - time());
    if($remaining <= 0){
        $orders[$order_id]['status'] = "expired";
        file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        deleteMsg($chat_id, $message_id); sendMessage($chat_id, "❌ <b>QR EXPIRED</b>\n\n5 minutes ke andar payment nahi hua. Ye QR ab valid nahi hai.\n\nNaya QR generate karke payment karo.", btn([["➕ New Payment","backkey"],["Back to Menu","back"]]));
        return;
    }
    editMsg(
        $chat_id, $message_id,
        "❌ <b>Not Payment Review</b>\n\nPayment abhi receive nahi hui hai.\nQR validity: <b>5 minutes</b>\n\nPayment karne ke baad <b>Verify Payment</b> dabao. Payment milte hi balance automatically add ho jayega.",
        btn([
            [["✅ Verify Payment","check_$order_id"]],
            [["❌ Cancel Payment","cancel_$order_id"]]
        ])
    );
}

function cancelOrder($chat_id, $message_id, $order_id){
    $orders = json_decode(file_get_contents("orders.json"), true);
    if(!isset($orders[$order_id])) return;

    $order = $orders[$order_id];
    if(($order['status'] ?? '') === 'paid') return;

    $orders[$order_id]['status'] = "cancelled";
    $orders[$order_id]['cancelled_at'] = time();
    file_put_contents("orders.json", json_encode($orders, JSON_UNESCAPED_UNICODE), LOCK_EX);

    $amount = formatMoney((float)($order['amount'] ?? 0));
    $caption = "❌ <b>Payment Cancelled</b>

Your QR for ₹{$amount} was cancelled — no charge was made.";

    // Remove the QR message and show a clean cancellation message like the screenshot.
    deleteMsg($chat_id, $message_id);
    sendMessage($chat_id, $caption, btn([
        ["💰 Add Balance Again", "again_{$order_id}"],
        ["Back to Menu", "back"]
    ]));
}

function repeatPayment($chat_id, $message_id, $old_order_id){
    $orders = json_decode(file_get_contents("orders.json"), true);
    if(!isset($orders[$old_order_id])){
        editMsg($chat_id, $message_id, "❌ Payment order nahi mila.", btn([["👈 Back to Menu","back"]]));
        return;
    }

    $order = $orders[$old_order_id];
    if(($order['status'] ?? '') !== 'cancelled'){
        editMsg($chat_id, $message_id, "❌ Is payment ko dobara start nahi kiya ja sakta.", btn([["👈 Back to Menu","back"]]));
        return;
    }

    $user_id = (int)($order['user'] ?? 0);
    $amount = (float)($order['amount'] ?? 0);
    if($user_id <= 0 || $amount < 1){
        editMsg($chat_id, $message_id, "❌ Invalid payment amount.", btn([["👈 Back to Menu","back"]]));
        return;
    }

    createFamPayOrder($chat_id, $message_id, $user_id, $amount);
}

// ========== SEND FUNCTIONS FOR BROADCAST ==========
function sendVideo($chat_id, $video, $caption, $reply_markup = null){
    global $website;
    $d = ['chat_id'=>$chat_id, 'video'=>$video, 'caption'=>$caption, 'parse_mode'=>'HTML'];
    if($reply_markup) $d['reply_markup'] = $reply_markup;
    return @file_get_contents($website."/sendVideo?".http_build_query($d));
}

function sendVoice($chat_id, $voice, $caption, $reply_markup = null){
    global $website;
    $d = ['chat_id'=>$chat_id, 'voice'=>$voice, 'caption'=>$caption, 'parse_mode'=>'HTML'];
    if($reply_markup) $d['reply_markup'] = $reply_markup;
    return @file_get_contents($website."/sendVoice?".http_build_query($d));
}

function sendDocument($chat_id, $document, $caption, $reply_markup = null){
    global $website;
    $d = ['chat_id'=>$chat_id, 'document'=>$document, 'caption'=>$caption, 'parse_mode'=>'HTML'];
    if($reply_markup) $d['reply_markup'] = $reply_markup;
    return @file_get_contents($website."/sendDocument?".http_build_query($d));
}

// ==================== HELPER FUNCTIONS ====================
function saveBalance($u, $b){
    $balances = json_decode(file_get_contents("balances.json"), true);
    $balances[$u] = $b;
    file_put_contents("balances.json", json_encode($balances));
}

function saveTemp($u, $k, $v){
    $t = json_decode(file_get_contents("temp.json"), true);
    $t[$u][$k] = $v;
    file_put_contents("temp.json", json_encode($t));
}

// ========== BUTTON THEME ==========
// Telegram Bot API supports predefined button styles:
// success = GREEN, danger = RED, primary = BLUE.
// This bot intentionally uses GREEN as the primary action colour.
function buttonStyle($callbackData){
    $d = strtolower((string)$callbackData);

    // Reference UI:
    // red = Shop, green = Add Balance/Lucky, blue = Profile/HowTo/Share/Support.
    $dangerWords = [
        "delete", "del", "remove", "reject", "ban", "cancel",
        "reset", "clear", "disable", "stop"
    ];

    if($d === "key_c" || $d === "backkey") return "danger";
    // STOCK OUT and MAINTENANCE must always use the full red button style.
    if(strpos($d, "planout_") === 0 || strpos($d, "stockplanon_") === 0 ||
       strpos($d, "maintplan_") === 0 || strpos($d, "maintprod_") === 0) return "danger";

    // A product that is under maintenance must also appear red in the Shop.
    if(strpos($d, "buy_") === 0){
        $productsFile = __DIR__ . "/products.json";
        if(is_file($productsFile)){
            $shopProducts = json_decode(file_get_contents($productsFile), true);
            $buyPid = substr($d, 4);
            if(is_array($shopProducts) && isset($shopProducts[$buyPid]) &&
               is_array($shopProducts[$buyPid]) && !empty($shopProducts[$buyPid]['maintenance'])){
                return "danger";
            }
        }
    }

    foreach($dangerWords as $word){
        if(strpos($d, $word) !== false) return "danger";
    }

    $blueButtons = [
        "profile", "howto", "share", "share_page", "share_link", "support",
        "back", "backkey", "backadmin"
    ];

    if($d === "backcat" || $d === "backshop" || $d === "shop") return "danger";
    if(in_array($d, $blueButtons, true)) return "primary";

    return "success";
}

// Build a real Telegram URL button. Unlike callback_data, this opens the
// configured How To Use link immediately with a single tap.
function makeUrlButton($text, $url, $iconId = "", $style = "primary"){
    $button = [
        "text" => (string)$text,
        "url" => (string)$url,
        "style" => $style
    ];

    if(preg_match('/^\\d{10,30}$/', (string)$iconId)){
        $button["icon_custom_emoji_id"] = (string)$iconId;
    }

    return $button;
}

// Keep admin-entered links safe for Telegram URL buttons.
// Accepts full http/https URLs and also common t.me links without a scheme.
function normalizeWebLink($link, $fallback = "https://t.me/YourHowToVideo"){
    $link = trim((string)$link);

    if($link === ""){
        return $fallback;
    }

    if(stripos($link, "t.me/") === 0){
        $link = "https://" . $link;
    } elseif(stripos($link, "telegram.me/") === 0){
        $link = "https://" . $link;
    }

    if(!preg_match('/^https?:\\/\\/[^\\s]+$/i', $link)){
        return $fallback;
    }

    return $link;
}

function makeButton($text, $callbackData){
    // Custom emoji IDs used by the reference-style Telegram buttons.
    $premiumIcons = [
        "shop"    => "6093562529978522804",
        "orders"  => "6113743082358841933",
        "profile" => "5256143829672672750",
        "addbal"  => "6210859306602995217",
        "proof"   => "5215420556089776398",
        "howto"   => "5427158904729513162",
        "lucky"   => "6091150429100447967",
        "support"   => "5368554037320900698",
        "share"     => "5260450573768990626",
        "share_page"=> "5260450573768990626",
        "share_link"=> "5260450573768990626",
        "back"      => "5877536313623711363",
        "key_C"     => "5879896690210639947",
        "backkey"   => "5875082500023258804"
    ];

    $button = [
        "text" => $text,
        "callback_data" => $callbackData,
        "style" => buttonStyle($callbackData)
    ];

    // Every dynamically-added shop product gets the same premium key emoji.
    if(strpos((string)$callbackData, "confirm_") === 0){
        $button["icon_custom_emoji_id"] = "5406865085471663921";
    } elseif(strpos((string)$callbackData, "buy_") === 0){
        // Each admin-added product can have its own Premium Custom Emoji ID.
        // Fall back to the existing premium shop icon for older products.
        $productButtonPid = substr((string)$callbackData, 4);
        $productEmojiId = "";
        $productsFile = __DIR__ . "/products.json";
        if(is_file($productsFile)){
            $productButtonProducts = json_decode(file_get_contents($productsFile), true);
            if(is_array($productButtonProducts) && isset($productButtonProducts[$productButtonPid]) && is_array($productButtonProducts[$productButtonPid])){
                $productEmojiId = trim((string)($productButtonProducts[$productButtonPid]['custom_emoji_id'] ?? ""));
            }
        }
        $button["icon_custom_emoji_id"] = preg_match('/^\d{10,30}$/', $productEmojiId)
            ? $productEmojiId
            : "6005570495603282482";
    } elseif(strpos((string)$callbackData, "plan_") === 0){
        $button["icon_custom_emoji_id"] = "5431750735870050726";
    } elseif($callbackData === "backcat" || $callbackData === "backshop"){
        $button["icon_custom_emoji_id"] = "5877536313623711363";
    } elseif(isset($premiumIcons[$callbackData])){
        $button["icon_custom_emoji_id"] = $premiumIcons[$callbackData];
    }

    return $button;
}

function btn($a){
    $k = [];
    foreach($a as $r){
        $x = [];
        if(isset($r[0]) && is_array($r[0])){
            foreach($r as $b){
                $x[] = makeButton($b[0], $b[1]);
            }
        } else {
            $x[] = makeButton($r[0], $r[1]);
        }
        $k[] = $x;
    }
    return json_encode(["inline_keyboard"=>$k], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

function answerCallback($i, $url = null, $text = null){
    global $website;

    $d = [
        "callback_query_id" => $i,
        "show_alert" => ($text !== null && $text !== "")
    ];

    if($url !== null && $url !== ""){
        $d["url"] = $url;
    }
    if($text !== null && $text !== ""){
        $d["text"] = $text;
    }

    $response = @file_get_contents($website."/answerCallbackQuery?".http_build_query($d));
    if($response === false){
        return false;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) && !empty($decoded["ok"]);
}

function sendMessage($c, $t, $r=null){
    global $website;
    $d = ['chat_id'=>$c, 'text'=>$t, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
    if($r) $d['reply_markup'] = $r;
    return @file_get_contents($website."/sendMessage?".http_build_query($d));
}

function editMessageCaption($c, $m, $caption, $r=null){
    global $website;
    $d = ['chat_id'=>$c, 'message_id'=>$m, 'caption'=>$caption, 'parse_mode'=>'HTML'];
    if($r) $d['reply_markup'] = $r;
    return @file_get_contents($website."/editMessageCaption?".http_build_query($d));
}

function editMsg($c, $m, $t, $r=null){
    global $website;
    $d = ['chat_id'=>$c, 'message_id'=>$m, 'text'=>$t, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
    if($r) $d['reply_markup'] = $r;
    @file_get_contents($website."/editMessageText?".http_build_query($d));
}

function sendPhoto($c, $p, $cap, $r=null){
    global $website;
    $d = ['chat_id'=>$c, 'photo'=>$p, 'caption'=>$cap, 'parse_mode'=>'HTML'];
    if($r) $d['reply_markup'] = $r;
    return @file_get_contents($website."/sendPhoto?".http_build_query($d));
}

function deleteMsg($c, $m){
    global $website;
    @file_get_contents($website."/deleteMessage?chat_id=$c&message_id=$m");
}

