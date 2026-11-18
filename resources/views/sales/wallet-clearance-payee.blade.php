<label>结清金额（元）<input name="amount" inputmode="decimal" required maxlength="13" value="{{ old('amount') }}" placeholder="例如 10.00"></label>
<label>收款方式<select name="payout_method"><option value="bank_transfer" @selected(old('payout_method')==='bank_transfer')>银行转账</option><option value="alipay" @selected(old('payout_method')==='alipay')>支付宝</option></select></label>
<label>收款人姓名<input name="payee_name" required maxlength="100" value="{{ old('payee_name') }}"></label>
<label>收款账号<input name="payee_account" required maxlength="128" value="{{ old('payee_account') }}"></label>
<label>开户行（银行转账必填）<input name="bank_name" maxlength="100" value="{{ old('bank_name') }}"></label>
