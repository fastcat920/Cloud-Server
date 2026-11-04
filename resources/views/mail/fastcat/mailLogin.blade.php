<div style="background-color: #f5f7fa; padding: 40px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
  <table width="600" border="0" align="center" cellpadding="0" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 600px; margin: 0 auto;">
    <tbody>
      <tr>
        <td>
          <!-- 主卡片 -->
          <div style="background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); overflow: hidden;">
            <!-- 头部 -->
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse;">
              <thead>
                <tr>
                  <td style="background-color: #4566ae; padding: 28px 32px;">
                    <div style="font-size: 22px; font-weight: 600; color: #ffffff; letter-spacing: -0.3px;">{{$name}}</div>
                  </td>
                </tr>
              </thead>
              <tbody>
                <!-- 通知标题 -->
                <tr>
                  <td style="padding: 32px 32px 0 32px;">
                    <div style="font-size: 20px; font-weight: 600; color: #1a1a1a; border-left: 3px solid #4566ae; padding-left: 14px;">登录到 {{$name}}</div>
                  </td>
                </tr>
                <!-- 内容 -->
                <tr>
                  <td style="padding: 16px 32px 24px 32px;">
                    <div style="font-size: 15px; line-height: 1.6; color: #4a4a4a;">
                      尊敬的用户您好！
                      <br><br>
                      您正在登录到 {{$name}}，请在 5 分钟内点击下方链接进行登录。如果您未授权该登录请求，请无视。
                      <br><br>
                      <div style="margin: 16px 0;">
                        <a href="{{$link}}" style="display: inline-block; background-color: #4566ae; color: #ffffff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.3s ease;">点击登录</a>
                      </div>
                      <div style="font-size: 13px; color: #8c8c8c; word-break: break-all; background-color: #f5f7fa; padding: 12px; border-radius: 8px; margin-top: 8px;">
                        如果按钮无法点击，请复制以下链接到浏览器打开：<br>
                        <a href="{{$link}}" style="color: #4566ae; text-decoration: none;">{{$link}}</a>
                      </div>
                      <br>
                      <span style="font-size: 13px; color: #8c8c8c;">（平台所有通知都会以官网公告以及邮件提醒，如果发现邮件在邮箱垃圾箱，还请点击【这不是垃圾邮件】，以便您第一时间收到平台通知）</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 底部链接 -->
          <div style="margin-top: 20px; text-align: center;">
            <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse;">
              <tbody>
                <tr>
                  <td style="padding: 16px 24px; background-color: #f8f9fc; border-radius: 12px;">
                    <a href="https://fastcat3.com" style="font-size: 14px; font-weight: 500; color: #4566ae; text-decoration: none;">打开{{$name}}官网</a>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </td>
      </tr>
    </tbody>
  </table>
</div>
