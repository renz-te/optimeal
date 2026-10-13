/**
 * OptiMeal Frontend API Service
 */

export async function getNotifications() {
  const res = await fetch('/api/notifications', {
    headers: {
      'Accept': 'application/json'
    }
  })
  if (!res.ok) {
    throw new Error(`Failed to fetch notifications: ${res.statusText}`)
  }
  return await res.json()
}

export async function markNotificationsRead(notificationId = 'all') {
  const res = await fetch('/api/notifications/read', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: JSON.stringify({ notification_id: notificationId })
  })
  if (!res.ok) {
    throw new Error(`Failed to mark notifications as read: ${res.statusText}`)
  }
  return await res.json()
}

export default {
  getNotifications,
  markNotificationsRead
}
