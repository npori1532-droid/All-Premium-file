import os
import requests
import base64
import telebot
from telebot import types
import time

bot = telebot.TeleBot("Bot Token Here")

user_data = {}

WAITING_FOR_SOURCE = 1
WAITING_FOR_TARGET = 2

@bot.message_handler(commands=['start'])
def send_welcome(message):
    bot.reply_to(message, "Send 1st Source")

@bot.message_handler(content_types=['photo'])
def handle_photo(message):
    try:
        chat_id = message.chat.id
        
        file_id = message.photo[-1].file_id
        file_info = bot.get_file(file_id)
        file_url = f"https://api.telegram.org/file/bot{bot.token}/{file_info.file_path}"
        
        img_data = requests.get(file_url).content
        
        if chat_id not in user_data:
            user_data[chat_id] = {
                'state': WAITING_FOR_TARGET,
                'source': img_data
            }
            bot.reply_to(message, "Now send me the target photo (the face you want to replace).")
        else:
            if user_data[chat_id]['state'] == WAITING_FOR_TARGET:
                user_data[chat_id]['target'] = img_data
                user_data[chat_id]['state'] = None
                
                bot.reply_to(message, "🔄 Processing face swap... Please wait.")
                
                source_base64 = base64.b64encode(user_data[chat_id]['source']).decode('utf-8')
                target_base64 = base64.b64encode(user_data[chat_id]['target']).decode('utf-8')
                
                api_url = "https://tobi-faceswap-api.vercel.app/api/swap"
                data = {
                    'source': source_base64,
                    'target': target_base64,
                    'security': {
                        'token': '0.ufDEMbVMT7mc9_XLsFDSK5CQqdj9Cx_Zjww0DevIvXN5M4fXQr3B9YtPdGkKAHjXBK6UC9rFcEbZbzCfkxxgmdTYV8iPzTby0C03dTKv5V9uXFYfwIVlqwNbIsfOK_rLRHIPB31bQ0ijSTEd-lLbllf3MkEcpkEZFFmmq8HMAuRuliCXFEdCwEB1HoYSJtvJEmDIVsooU3gYdrCm5yOJ8_lZ4DiHCSvy7P8-YxwJKkapJNCMUCFIfJbWDkDzvh8DGPyTRoHbURX8kClfImmPrGcqlfd7kkoNRcudS25IbNf1CGBsh8V96MtEhnTZvOpZfnp5dpV7MfgwOgvx7hUazUaC_wxQE63Aa0uOPuGvJ70BNrmeZIIrY9roD1Koj316L4g2BZ_LLZZF11wcrNNon8UXB0iVudiNCJyDQCxLUmblXUpt4IUvRoiOqXBNtWtLqY0su0ieVB0jjyDf_-zs7wc8WQ_jqp-NsTxgKOgvZYWV6Elz_lf4cNxGHZJ5BdcyLEoRBH3cksvwoncmYOy5Ulco22QT-x2z06xVFBZYZMVulxAcmvQemKfSFKsNaDxwor35p-amn9Vevhyb-GzA_oIoaTmc0fVXSshax2rdFQHQms86fZ_jkTieRpyIuX0mI3C5jLGIiOXzWxNgax9eZeQstYjIh8BIdMiTIUHfyKVTgtoLbK0hjTUTP0xDlCLnOt5qHdwe_iTWedBsswAJWYdtIxw0YUfIU22GMYrJoekOrQErawNlU5yT-LhXquBQY3EBtEup4JMWLendSh68d6HqjN2T3sAfVw0nY5jg7_5LJwj5gqEk57devNN8GGhogJpfdGzYoNGja22IZIuDnPPmWTpGx4VcLOLknSHrzio.tXUN6eooS69z3QtBp-DY1g.d882822dfe05be2b36ed1950554e1bac753abfe304a289adc4289b3f0d517356',
                        'type': 'invisible',
                        'id': 'faceswapper'
                    }
                }
                
                headers = {'Content-Type': 'application/json'}
                response = requests.post(api_url, json=data, headers=headers)
                
                if response.status_code == 200:
                    response_data = response.json()
                    if 'result' in response_data:
                        image_data = base64.b64decode(response_data['result'])
                       
                        if not os.path.exists('results'):
                            os.makedirs('results')
                        
                        filename = f"result_{int(time.time())}.png"
                        filepath = os.path.join('results', filename)
                        
                        with open(filepath, 'wb') as f:
                            f.write(image_data)
                        
                        with open(filepath, 'rb') as photo:
                            bot.send_photo(chat_id, photo)
                       
                        del user_data[chat_id]
                    else:
                        bot.reply_to(message, "❌ Error: No result from face swap API")
                else:
                    bot.reply_to(message, f"❌ Error: Face swap API request failed with status {response.status_code}")
                
            else:
                bot.reply_to(message, "⚠️ Please send the target photo (the face you want to replace).")
    
    except Exception as e:
        bot.reply_to(message, f"❌ An error occurred: {str(e)}")
        if chat_id in user_data:
            del user_data[chat_id]

@bot.message_handler(func=lambda message: True)
def handle_text(message):
    chat_id = message.chat.id
    if chat_id in user_data:
        bot.reply_to(message, " Please send the target photo (the face you want to replace).")
    else:
        bot.reply_to(message, "📸 Please send your photo first (the face to use).")

print("Bot is running...")
if __name__ == "__main__":
    bot.infinity_polling(skip_pending=True)
