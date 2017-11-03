<div class="alert alert-warning" role="alert">
   <strong><i>Note:</i></strong> The sofort-Plugin has been developed for use with the online store Ceres and only works with its structure or other template plugins. 
</div>

# Online Bank Transfer - Easy Online payments for plentymarkets Online-Shops

"Onine Bank Transfer." formerly known as SOFORT is the direct payment system provided by Sofort GmbH - A Klarna Group Company. It allows you to make a wire transfer of the respective amount during the order process using your online banking account - easy, quick and secure – without registration. The online shop can process and deliver your order faster thanks to our real-time transaction notification.

## Creating a Sofort - Merchant account

You need to register at our [Merchant Portal](https://www.sofort.com/payment/users/register) before you are able to use Sofort as a payment method. After registration you will receive the correct information and credentials needed to install and configure the payment method. 

## Installing the Sofort-Plugin in plentymarkets

Before using Sofort-Plugin, you need to install the module from the Git or plentyMarketplace.

**Installing Sofort-Plugin via plentyMarketplace:**

1. Open [plentyMarketplace](https://marketplace.plentymarkets.com/) in your browser
2. Select the Sofort plugin under **Payment** → **Sofort**
3. Choose **Go to checkout** (login) and purchase
4. Go to plentymarkets shop backend
5. Click on the Menu **Plugin » Purchases**
6. Press **Install**

**Installing Sofort-Plugin via GIT:**

1. Open the Menu **Plugins » Git**
2. Click on **New Plugin**. The frame **Settings** will open
3. Connect to your Github Account by filling in **User Name** and **Password** 
4. Fill the Remote URL of the Sofort-Plugin: <https://github.com/frontend-studios/plugin-payment-sofort.git>
5. Select **Auto fetch**
6. Then click on **Save**

## Configuring Sofort in plentymarkets

Before using Sofort-Plugins, you need to connect your Sofort-account with your plentymarkets-system.

##### connecting to the Sofort-account:
  
1. Open the menu **Plugins » Plugin overview**.
2. Click on **Sofort** Plugin and choose **Configuration**.
3. Type in the configuration key 
	→ You receive the configuration key after successful registration as a Merchant.
4. Other additional configurations can be set up in the Menu **Extended Settings**.
5. Save the configurations.

<table>
  <caption>Tab. 1: Sofort Plugin Settings / Base Settings</caption>
  <thead>
    <th>
      Setting
    </th>
    <th>
      Explanation
    </th>
  </thead>
  <tbody>
    <tr>
      <td>
        <b>Activate Sofort</b>
      </td>
      <td><b>Default:</b> <i>No</i>
      </td>
    </tr>
    <tr>
      <td>
        <b>Configuration key</b>
      </td>
      <td><strong><i>Important:</i></strong> You'll receive the configuration key after registration (https://www.sofort.com/payment/users/register).
      <br />Without input the payment method won't be active.
      <br />Example of a correct configuration key:
      <br />{Customer number}:{Project ID}:{API key}
      </td>
    </tr>
  </tbody>
</table>

<table>
  <caption>Tab. 2: Sofort Plugin Settings / Extended Settings</caption>
  <thead>
    <th>
      Setting
    </th>
    <th>
      Explanation
    </th>
  </thead>
  <tbody>
    <tr>
      <td>
        <b>Reason (line 1) *Mandatory</b>
      </td>
      <td>
        Sets up the reason for the bank transfer (max. 27 chars - special chars will be replaced/removed). The following placeholders are allowed:<br />
        {{transaction_id}} => Transaction-ID<br />
        {{customer_name}} => Name of the customer<br />
        {{customer_email}} => E-Mail address of the customer<br />
        <b>Default:</b> <i>{{transaction_id}}</i>
      </td>
    </tr>
    <tr>
      <td>
        <b>Reason (line 2)</b>
      </td>
      <td>
        <i>refer Reason 1</i><br />
        Additionally following placeholders are possible:<br />
        {{customer_id}} => Customer-ID<br />
        {{customer_company}} => Company name of the customer<br />
        <b>Default:</b> <i>-</i>
      </td>
    </tr>
  </tbody>
</table>

## administrate payment methods

In this chapter you will find out, how to offer the payment methods in your webshop.

### activate Sofort-Plugin

After installing Sofort-Plugin, you need to activate the plugin in the main configuration and at least set the configuration key. Afterwards Sofort ist available without any further adjustments. This payment method appears depending on the priority settings next to other activated payment methods.
