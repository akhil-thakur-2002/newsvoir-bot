function doPost(e) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var data = JSON.parse(e.postData.contents);
  sheet.appendRow([
    data.timestamp,
    data.type,
    data.message,
    data.data,
    data.url
  ]);
  return ContentService.createTextOutput("OK");
}
